<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanParam(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

$action = cleanParam('action');
$q      = cleanParam('q');
$type   = cleanParam('type', 'Member');

if ($action !== 'lookup' || mb_strlen($q) < 2) {
    jsonOut([]);
}

$typeMap = [
    'Member'      => 'Member',
    'Participant' => 'Beneficiary',
    'Beneficiary' => 'Beneficiary',
    'Startup'     => 'Startup',
    'Guest'        => 'Guest',
];

$type = $typeMap[$type] ?? '';

if ($type === '' || $type === 'Guest') {
    jsonOut([]);
}

// Public endpoint: partial matches on names only, exact matches on email or
// phone, and no contact details in the response. event-register.php fills
// email/phone from the selected record server-side when left blank.
$rateKey = 'event_register_lookup|' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (ims_rate_limit_too_many($rateKey, 30, 60)) {
    http_response_code(429);
    jsonOut([]);
}
ims_rate_limit_hit($rateKey, 60);

$like = '%' . $q . '%';
$results = [];

try {

    if ($type === 'Startup') {
        $stmt = $conn->prepare("
            SELECT
                a.application_id AS id,
                COALESCE(NULLIF(a.startup_name, ''), NULLIF(a.contact_person, ''), 'Startup') AS attendee_name,
                COALESCE(a.startup_name, '') AS organization,
                CONCAT(
                    'Startup',
                    CASE WHEN COALESCE(a.sector, '') <> '' THEN CONCAT(' | ', a.sector) ELSE '' END,
                    CASE WHEN COALESCE(a.business_stage, '') <> '' THEN CONCAT(' | ', a.business_stage) ELSE '' END
                ) AS sub_label
            FROM applications a
            WHERE a.status <> 'Draft'
              AND (a.startup_name LIKE ?
               OR a.contact_person LIKE ?
               OR a.email = ?
               OR a.phone = ?
               OR a.sector LIKE ?)
            ORDER BY a.startup_name ASC
            LIMIT 10
        ");

        $stmt->bind_param("sssss", $like, $like, $q, $q, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $results[] = [
                'id'            => (int)$row['id'],
                'record_type'   => 'Startup',
                'attendee_name' => (string)$row['attendee_name'],
                'organization'  => (string)$row['organization'],
                'sub_label'     => (string)$row['sub_label'],
            ];
        }

        jsonOut($results);
    }

    if ($type === 'Beneficiary') {
        $stmt = $conn->prepare("
            SELECT
                b.beneficiary_id AS id,
                CONCAT_WS(' ', b.first_name, b.last_name) AS attendee_name,
                '' AS organization,
                CONCAT(
                    'Participant',
                    CASE WHEN COALESCE(b.district, '') <> '' THEN CONCAT(' | ', b.district) ELSE '' END
                ) AS sub_label
            FROM beneficiaries b
            WHERE CONCAT_WS(' ', b.first_name, b.last_name) LIKE ?
               OR b.email = ?
               OR b.phone = ?
            ORDER BY b.first_name ASC, b.last_name ASC
            LIMIT 10
        ");

        $stmt->bind_param("sss", $like, $q, $q);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $results[] = [
                'id'            => (int)$row['id'],
                'record_type'   => 'Beneficiary',
                'attendee_name' => trim((string)$row['attendee_name']),
                'organization'  => '',
                'sub_label'     => (string)$row['sub_label'],
            ];
        }

        jsonOut($results);
    }

    $stmt = $conn->prepare("
        SELECT
            m.member_id AS id,
            COALESCE(NULLIF(m.company_name, ''), NULLIF(m.membership_number, ''), 'Member') AS attendee_name,
            COALESCE(m.company_name, '') AS organization,
            CONCAT(
                'Member',
                CASE WHEN COALESCE(m.member_type, '') <> '' THEN CONCAT(' | ', m.member_type) ELSE '' END,
                CASE WHEN COALESCE(m.membership_number, '') <> '' THEN CONCAT(' | ', m.membership_number) ELSE '' END,
                CASE WHEN COALESCE(m.membership_status, '') <> '' THEN CONCAT(' | ', m.membership_status) ELSE '' END
            ) AS sub_label
        FROM members m
        WHERE m.company_name LIKE ?
           OR m.membership_number = ?
           OR m.email = ?
        ORDER BY m.company_name ASC, m.membership_number ASC
        LIMIT 10
    ");

    $stmt->bind_param("sss", $like, $q, $q);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $results[] = [
            'id'            => (int)$row['id'],
            'record_type'   => 'Member',
            'attendee_name' => (string)$row['attendee_name'],
            'organization'  => (string)$row['organization'],
            'sub_label'     => (string)$row['sub_label'],
        ];
    }

    jsonOut($results);

} catch (Throwable $e) {
    error_log('event-register lookup failed: ' . $e->getMessage());
    http_response_code(500);

    jsonOut([
        [
            'id'            => 0,
            'record_type'   => 'Error',
            'attendee_name' => 'Lookup failed',
            'email'         => '',
            'phone'         => '',
            'organization'  => '',
            'sub_label'     => 'Please fill in your details manually.',
        ]
    ]);
}