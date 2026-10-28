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
        ims_brand_name() . ' Events'
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
/* Events: month calendar grid, filter sizing, CRUD modal form and staff picker. */
.ev-filters { margin-bottom: 0; }
.ev-filters > .form-group.filters-grow { flex: 1 1 220px; min-width: 180px; }

.ev-card-sub { display: block; margin-top: 2px; font-weight: 400; }

.ev-calendar-scroll { overflow-x: auto; }
.calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.calendar-weekday {
    padding: var(--space-2);
    text-align: center;
    background: var(--surface-hover);
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    color: var(--ink-300);
    font-size: var(--fs-xs);
    font-weight: 800;
    text-transform: uppercase;
}
.calendar-day {
    min-height: 120px;
    padding: 7px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.calendar-day.other { background: var(--surface-hover); }
.calendar-day.today { box-shadow: inset 0 0 0 2px var(--brand-500); }
.calendar-number { margin-bottom: 6px; color: var(--ink-400); font-size: var(--fs-xs); font-weight: 800; }
.calendar-event {
    display: block;
    width: 100%;
    margin-bottom: 4px;
    padding: 5px 6px;
    border: 0;
    border-left: 3px solid var(--brand-500);
    border-radius: var(--radius-sm);
    background: var(--brand-50);
    color: var(--brand-700);
    text-align: left;
    cursor: pointer;
    font-size: 11px;
}
.calendar-event.google { background: var(--blue-bg); border-left-color: var(--blue-fg); color: var(--blue-fg); }
.calendar-event strong { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
@media (max-width: 750px) { .calendar-grid { min-width: 850px; } }

.ev-source-badge { margin-top: 4px; }
.ev-pagination { align-items: center; margin-top: 0; }
.ev-pagination .ev-page-summary { margin-left: 7px; }

/* CRUD modals use .modal-overlay/.modal-dialog; .ev-modal stays as the JS hook. */
.ev-modal { z-index: 1000000; }
.ev-modal form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.ev-modal-actions { display: flex; justify-content: flex-end; gap: var(--space-2); flex-wrap: wrap; margin-top: 17px; padding-top: 14px; border-top: 1px solid var(--border); }
.ev-modal-details { z-index: 999999; }
.ev-contents { display: contents; }
body.ev-modal-open { overflow: hidden; }
.ev-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); }
.ev-form-grid .full { grid-column: 1 / -1; }
.ev-add-date { margin-top: 7px; }
.ev-date-list { display: flex; flex-direction: column; gap: 7px; }
.ev-date-row { display: flex; gap: 7px; align-items: center; }
.ev-date-row input { flex: 1; }
.ev-link-target { display: none; }
.ev-link-target.open { display: block; }
.ev-danger-note { margin-top: 5px; }

.ev-details-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-2); }
.ev-detail { padding: 9px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-hover); }
.ev-detail small { display: block; color: var(--ink-300); font-size: 11px; text-transform: uppercase; }
.ev-detail strong { font-size: var(--fs-sm); color: var(--ink-900); }
.ev-details-desc { margin: var(--space-4) 0 0; color: var(--ink-400); font-size: var(--fs-sm); white-space: pre-wrap; }
.ev-details-links { display: flex; gap: var(--space-2); flex-wrap: wrap; margin-top: var(--space-3); }

.ev-staff-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.ev-staff-count { font-size: var(--fs-xs); font-weight: 600; color: var(--brand-700); }
.ev-staff-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.ev-staff-chips:empty { display: none; }
.ev-staff-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 10px; border: 1px solid var(--brand-200); border-radius: var(--radius-pill); background: var(--brand-50); color: var(--brand-700); font-size: var(--fs-xs); font-weight: 600; }
.ev-staff-chip button { width: 20px; height: 20px; border: 0; border-radius: 50%; background: transparent; color: inherit; font-size: 14px; line-height: 1; cursor: pointer; }
.ev-staff-chip button:hover,
.ev-staff-chip button:focus-visible { background: var(--brand-100); }
.ev-staff-tools { display: flex; gap: 14px; margin: 6px 0; }
.ev-staff-link { border: 0; padding: 2px 0; background: none; color: var(--brand-700); font-size: var(--fs-xs); font-weight: 600; cursor: pointer; }
.ev-staff-link:hover { text-decoration: underline; }
.ev-staff-list { max-height: 220px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-card); }
.ev-staff-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--ink-50); cursor: pointer; margin: 0; }
.ev-staff-item:last-of-type { border-bottom: 0; }
.ev-staff-item:hover { background: var(--surface-hover); }
.ev-staff-item:has(input:checked) { background: var(--brand-50); }
.ev-staff-item input { width: 18px; height: 18px; flex: none; accent-color: var(--brand-600); }
.ev-staff-item[hidden] { display: none; }
.ev-staff-avatar { width: 30px; height: 30px; flex: none; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--brand-100); color: var(--brand-700); font-size: var(--fs-sm); font-weight: 700; }
.ev-staff-text { display: flex; flex-direction: column; min-width: 0; }
.ev-staff-text strong { font-size: var(--fs-sm); color: var(--ink-900); }
.ev-staff-text small { font-size: var(--fs-xs); color: var(--ink-300); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ev-staff-empty { margin: 0; padding: 12px; font-size: var(--fs-xs); color: var(--ink-300); }
.ev-staff-hint { display: block; margin-top: 6px; font-size: var(--fs-xs); color: var(--ink-300); }

@media (max-width: 720px) {
    .ev-form-grid { grid-template-columns: 1fr; }
    .ev-form-grid .full { grid-column: auto; }
    .ev-staff-list { max-height: 180px; }
    .ev-details-grid { grid-template-columns: 1fr; }
}
</style>


<div class="page-stack">


    <div class="page-hero">

        <div>

            <h1>

                <i class="fas fa-calendar-days" aria-hidden="true"></i>

                Events Management

            </h1>

            <p>

                <?= htmlspecialchars(ims_brand_name()) ?> events from IMS and the connected Google Calendar.

            </p>

        </div>


        <div class="hero-actions">

            <span class="badge <?= $googleConnected ? 'badge-success' : 'badge-warning' ?>">

                <i class="fab fa-google" aria-hidden="true"></i>

                <?= $googleConnected
                    ? 'Google Calendar Connected'
                    : 'Google Calendar Not Connected' ?>

            </span>


            <button
                type="button"
                class="btn btn-primary"
                onclick="openAddEventModal()"
            >

                <i class="fas fa-plus" aria-hidden="true"></i>

                Add Event

            </button>

        </div>

    </div>


    <?php if (
        $googleFetchError !== ''
    ): ?>

        <div class="alert alert-danger">

            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>

            Google Calendar fetch failed:

            <?= ev_h(
                $googleFetchError
            ) ?>

        </div>

    <?php endif; ?>


    <div class="stats-grid reports-stats">

        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-calendar" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Events Displayed</span>
                <span class="stat-value"><?= $statistics['all'] ?></span>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-calendar-day" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Upcoming</span>
                <span class="stat-value"><?= $statistics['upcoming'] ?></span>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-database" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">IMS Events</span>
                <span class="stat-value"><?= $statistics['ims'] ?></span>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon bg-teal"><i class="fab fa-google" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Google Only</span>
                <span class="stat-value"><?= $statistics['google'] ?></span>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
            <div class="stat-info">
                <span class="stat-label">Completed</span>
                <span class="stat-value"><?= $statistics['completed'] ?></span>
            </div>
        </div>

    </div>


    <div class="card">

    <nav class="tab-nav" aria-label="Event views">

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
                tab-btn
                <?= $activeTab
                    ===
                    'calendar'
                    ? 'active'
                    : '' ?>
            "
        >

            <i class="fas fa-calendar-alt" aria-hidden="true"></i>

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
                tab-btn
                <?= $activeTab
                    ===
                    'table'
                    ? 'active'
                    : '' ?>
            "
        >

            <i class="fas fa-table-list" aria-hidden="true"></i>

            Table

        </a>

    </nav>


    <div class="card-body">
    <form
        method="GET"
        class="filters-bar ev-filters"
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


        <div class="form-group filters-grow">

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


        <div class="form-group">

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


        <div class="form-group">

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


        <div class="form-group">

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


        <div class="form-group">

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


        <div class="form-group">

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
    </div>

    </div>


    <?php if (
        $activeTab
        ===
        'calendar'
    ): ?>


        <div class="card">


            <div class="card-header">

                <div>

                    <h3>

                        <i class="fas fa-calendar" aria-hidden="true"></i>

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
                        class="cell-muted ev-card-sub"
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

                <div class="empty-state">

                    <i class="fas fa-calendar-alt" aria-hidden="true"></i>

                    <p>Select a month to display the calendar.</p>

                </div>

            <?php else: ?>


                <div class="ev-calendar-scroll">
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
                        class="ev-contents"
                    ></div>

                </div>
                </div>


            <?php endif; ?>

        </div>


    <?php else: ?>


        <div class="card">


            <div class="card-header">

                <h3>

                    <i class="fas fa-table-list" aria-hidden="true"></i>

                    Hive Events

                </h3>


                <strong>

                    <?= number_format(
                        $tableTotalRecords
                    ) ?>

                    records

                </strong>

            </div>


            <div class="table-responsive">


                <table class="table table-stack">


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
                                >

                                    <div class="empty-state">
                                        <i class="fas fa-calendar-xmark" aria-hidden="true"></i>
                                        <p>No events found.</p>
                                    </div>

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
                                            badge
                                            ev-source-badge
                                            <?= $eventSource
                                                ===
                                                'google'
                                                ? 'badge-info'
                                                : 'badge-warning' ?>
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

                                    <span class="badge badge-status-<?= ev_h(
                                        str_replace(' ', '', (string)($event['event_status'] ?? ''))
                                    ) ?>">
                                    <?= ev_h(
                                        $event[
                                            'event_status'
                                        ]
                                        ?? ''
                                    ) ?>
                                    </span>

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
                                                btn-secondary
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
                                    <div class="table-actions">
                                        <button
                                            type="button"
                                            class="btn btn-info btn-sm"
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
                                                class="btn btn-soft btn-sm"
                                                title="Edit event"
                                                onclick="openEditEventModal(<?= (int)$event['event_id'] ?>)"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <?php if ($canDeleteEvents): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-danger btn-sm"
                                                    title="Delete event"
                                                    onclick="openDeleteEventModal(<?= (int)$event['event_id'] ?>)"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($googleConnected): ?>
                                                <a
                                                    href="includes/google-calendar-sync.php?type=event&id=<?= (int)$event['event_id'] ?>&return=<?= rawurlencode('../events?tab=table') ?>"
                                                    class="btn btn-secondary btn-sm"
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
                                                class="btn btn-secondary btn-sm"
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
                <div class="card-body">
                <div class="pagination ev-pagination">

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
                                class="current"
                                aria-current="page"
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

                    <span class="cell-muted ev-page-summary">
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
                </div>
            <?php endif; ?>


        </div>


    <?php endif; ?>


</div><!-- /.page-stack -->



<div class="ev-modal modal-overlay" id="addEventModal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-lg">
        <div class="modal-dialog-header">
            <h3 class="modal-dialog-title"><i class="fas fa-calendar-plus"></i> Add Event</h3>
            <button type="button" class="modal-dialog-close" aria-label="Close" onclick="closeCrudModal('addEventModal')">&times;</button>
        </div>
        <form method="POST" action="includes/events-process.php">
            <div class="modal-dialog-body">
                
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
                        <button type="button" class="btn btn-secondary btn-sm ev-add-date" onclick="addEventDateRow('addEventDates')">
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
                    <button type="button" class="btn btn-secondary" onclick="closeCrudModal('addEventModal')">Cancel</button>
                    <button type="submit" name="add_event" class="btn btn-primary"><i class="fas fa-save"></i> Save Event</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="ev-modal modal-overlay" id="editEventModal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-lg">
        <div class="modal-dialog-header">
            <h3 class="modal-dialog-title"><i class="fas fa-pen"></i> Edit Event</h3>
            <button type="button" class="modal-dialog-close" aria-label="Close" onclick="closeCrudModal('editEventModal')">&times;</button>
        </div>
        <form method="POST" action="includes/events-process.php">
            <input type="hidden" name="event_id" id="editEventId">
            <div class="modal-dialog-body">
                
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
                        <button type="button" class="btn btn-secondary btn-sm ev-add-date" onclick="addEventDateRow('editEventDates')">
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
                    <button type="button" class="btn btn-secondary" onclick="closeCrudModal('editEventModal')">Cancel</button>
                    <button type="submit" name="edit_event" class="btn btn-primary"><i class="fas fa-save"></i> Update Event</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="ev-modal modal-overlay" id="deleteEventModal" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-dialog-header">
            <h3 class="modal-dialog-title"><i class="fas fa-trash"></i> Delete Event</h3>
            <button type="button" class="modal-dialog-close" aria-label="Close" onclick="closeCrudModal('deleteEventModal')">&times;</button>
        </div>
        <form method="POST" action="includes/events-process.php">
            <input type="hidden" name="event_id" id="deleteEventId">
            <div class="modal-dialog-body">
                <div class="alert alert-danger">Delete <strong id="deleteEventTitle">this event</strong>?<div class="ev-danger-note">This action cannot be undone.</div></div>
                <div class="ev-modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCrudModal('deleteEventModal')">Cancel</button>
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
    class="modal-overlay ev-modal-details"
    id="eventDetailsModal"
>

    <div class="modal-dialog">


        <div class="modal-dialog-header">

            <h3
                id="detailsTitle"
                class="modal-dialog-title"
            ></h3>


            <button
                type="button"
                class="modal-dialog-close"
                aria-label="Close"
                onclick="
                    closeEventDetails()
                "
            >

                &times;

            </button>

        </div>


        <div class="modal-dialog-body">

        <div class="ev-details-grid">


            <div class="ev-detail">

                <small>Date</small>

                <strong id="detailsDate"></strong>

            </div>


            <div class="ev-detail">

                <small>Time</small>

                <strong id="detailsTime"></strong>

            </div>


            <div class="ev-detail">

                <small>Type</small>

                <strong id="detailsType"></strong>

            </div>


            <div class="ev-detail">

                <small>Source</small>

                <strong id="detailsSource"></strong>

            </div>


            <div class="ev-detail">

                <small>Venue</small>

                <strong id="detailsVenue"></strong>

            </div>


            <div class="ev-detail">

                <small>Organiser</small>

                <strong id="detailsOrganizer"></strong>

            </div>


        </div>


        <p
            id="detailsDescription"
            class="ev-details-desc"
        ></p>


        <div
            id="detailsLinks"
            class="ev-details-links"
        ></div>

        </div>


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
                class="btn btn-secondary btn-sm"
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