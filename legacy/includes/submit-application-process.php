<?php
declare(strict_types=1);

// config.php starts the session with the hardened cookie settings.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

function store_application_old_input(array $source): void
{
    $old = [];

    foreach ($source as $key => $value) {
        if ($key === 'csrf_token') {
            continue;
        }

        if (is_array($value)) {
            $old[$key] = $value;
            continue;
        }

        $old[$key] = is_string($value) ? trim($value) : $value;
    }

    /*
     * Unchecked checkbox groups are omitted by browsers. Explicitly add the
     * known groups as empty arrays so an error does not restore old checked
     * values from an existing database draft.
     */
    foreach ([
        'sector_focus',
        'heard_about',
        'regions_served',
        'revenue_models',
        'data_uses',
        'incubation_programmes',
        'founders',
        'other_team_members',
        'external_funding_details',
    ] as $arrayField) {
        if (!array_key_exists($arrayField, $old)) {
            $old[$arrayField] = [];
        }
    }

    $_SESSION['application_old_input'] = $old;
    $_SESSION['application_old_input_present'] = true;
}

function clear_application_old_input(): void
{
    unset(
        $_SESSION['application_old_input'],
        $_SESSION['application_old_input_present']
    );
}

function redirect_back(int $opportunityId, int $draftId = 0): never
{
    $url = '/submit-application?opportunity_id=' . $opportunityId;

    if ($draftId > 0) {
        $url .= '&draft_id=' . $draftId;
    }

    header('Location: ' . $url);
    exit;
}

function fail_request(string $message, int $opportunityId = 0, int $draftId = 0): never
{
    $_SESSION['error'] = $message;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST)) {
        store_application_old_input($_POST);
    }

    if ($opportunityId > 0) {
        redirect_back($opportunityId, $draftId);
    }

    header('Location: /apply-embed');
    exit;
}

function post_string(string $key): ?string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return null;

    $value = trim((string)$_POST[$key]);
    return $value === '' ? null : $value;
}

function post_int(string $key): ?int
{
    $value = post_string($key);
    if ($value === null) return null;

    return filter_var($value, FILTER_VALIDATE_INT) !== false
        ? (int)$value
        : null;
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? $value : [];
}

function clean_scalar_array(array $values): array
{
    $clean = [];

    foreach ($values as $value) {
        if (is_array($value)) continue;

        $value = trim((string)$value);

        if ($value !== '') {
            $clean[] = $value;
        }
    }

    return array_values(array_unique($clean));
}

function clean_rows(array $rows, array $allowedKeys): array
{
    $cleanRows = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $clean = [];
        $hasValue = false;

        foreach ($allowedKeys as $key) {
            $value = isset($row[$key]) && !is_array($row[$key])
                ? trim((string)$row[$key])
                : '';

            $clean[$key] = $value;

            if ($value !== '') {
                $hasValue = true;
            }
        }

        if ($hasValue) {
            $cleanRows[] = $clean;
        }
    }

    return $cleanRows;
}

function json_value(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '[]';
}

function word_count_utf8(?string $value): int
{
    $value = trim((string)$value);

    if ($value === '') return 0;

    preg_match_all('/\S+/u', $value, $matches);
    return count($matches[0] ?? []);
}

function validate_word_limit(
    array $data,
    string $field,
    string $label,
    int $maxWords,
    int $opportunityId,
    int $draftId
): void {
    if (word_count_utf8($data[$field] ?? null) > $maxWords) {
        fail_request(
            "{$label} must not exceed {$maxWords} words.",
            $opportunityId,
            $draftId
        );
    }
}

function is_yes(?string $value): bool
{
    return $value === 'Yes';
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$csrfPosted = $_POST['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($csrfPosted) ||
    $csrfPosted === '' ||
    !is_string($csrfSession) ||
    $csrfSession === '' ||
    !hash_equals($csrfSession, $csrfPosted)
) {
    $csrfOpportunityId = isset($_POST['opportunity_id']) && !is_array($_POST['opportunity_id'])
        ? (int)$_POST['opportunity_id']
        : 0;
    $csrfDraftId = isset($_POST['draft_id']) && !is_array($_POST['draft_id'])
        ? (int)$_POST['draft_id']
        : 0;

    fail_request(
        'Invalid or expired form token. Please reload the form and try again.',
        $csrfOpportunityId,
        $csrfDraftId
    );
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/*
|--------------------------------------------------------------------------
| REQUEST
|--------------------------------------------------------------------------
*/
$action = post_string('action') ?? '';
$opportunityId = post_int('opportunity_id') ?? 0;
$draftId = post_int('draft_id') ?? 0;

if (!in_array($action, ['save_draft', 'submit'], true) || $opportunityId < 1) {
    fail_request('Invalid application request.');
}

$isSubmit = $action === 'submit';

/*
|--------------------------------------------------------------------------
| OPPORTUNITY
|--------------------------------------------------------------------------
| cohort_id is read from the opportunity and automatically copied into the
| application record. Applicants never choose the cohort themselves.
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        opportunity_id,
        cohort_id,
        opportunity_title,
        max_applicants,
        min_team_size,
        max_team_size,
        deadline,
        status
    FROM application_opportunities
    WHERE opportunity_id = ?
      AND status = 'Published'
      AND deadline >= CURDATE()
    LIMIT 1
");

if (!$stmt) {
    fail_request('Unable to validate this opportunity.', $opportunityId, $draftId);
}

$stmt->bind_param('i', $opportunityId);
$stmt->execute();
$opportunity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$opportunity) {
    fail_request('This opportunity is no longer accepting applications.', $opportunityId, $draftId);
}

$cohortId = !empty($opportunity['cohort_id'])
    ? (int)$opportunity['cohort_id']
    : null;

/*
|--------------------------------------------------------------------------
| REPEATABLE / MULTI-VALUE FIELDS
|--------------------------------------------------------------------------
*/
$incubationProgrammes = clean_rows(
    post_array('incubation_programmes'),
    ['programme', 'organisation', 'year', 'outcomes']
);

$founders = clean_rows(
    post_array('founders'),
    ['name', 'role', 'age', 'gender', 'shareholding', 'full_time', 'pwd_status', 'pwd_type']
);

$otherTeamMembers = clean_rows(
    post_array('other_team_members'),
    ['name', 'role', 'age', 'gender', 'pwd_status', 'pwd_type']
);

$externalFundingDetails = clean_rows(
    post_array('external_funding_details'),
    ['funder', 'type', 'amount', 'currency', 'year', 'status']
);

/*
|--------------------------------------------------------------------------
| FORM DATA
|--------------------------------------------------------------------------
*/
$data = [
    'cohort_id'                     => $cohortId,

    'startup_name'                  => post_string('startup_name'),
    'has_physical_location'         => post_string('has_physical_location'),
    'district'                      => post_string('district'),
    'city_town'                     => post_string('city_town'),
    'physical_address'              => post_string('physical_address'),
    'google_maps_link'              => post_string('google_maps_link'),
    'founded_month'                 => post_string('founded_month'),
    'formally_registered'           => post_string('formally_registered'),
    'legal_status'                  => post_string('legal_status'),
    'legal_status_other'            => post_string('legal_status_other'),
    'registration_authority'        => post_string('registration_authority'),
    'registration_authority_other'  => post_string('registration_authority_other'),
    'business_stage'                => post_string('business_stage'),
    'sector_focus'                  => json_value(clean_scalar_array(post_array('sector_focus'))),
    'website'                       => post_string('website'),
    'social_media_links'            => post_string('social_media_links'),
    'incubation_participated'       => post_string('incubation_participated'),
    'incubation_programmes'         => json_value($incubationProgrammes),
    'heard_about'                   => json_value(clean_scalar_array(post_array('heard_about'))),
    'heard_social_platform'         => post_string('heard_social_platform'),
    'heard_media_station'           => post_string('heard_media_station'),
    'heard_other'                   => post_string('heard_other'),

    'founders'                      => json_value($founders),
    'other_team_members'            => json_value($otherTeamMembers),
    'full_time_staff'               => post_int('full_time_staff'),
    'part_time_staff'               => post_int('part_time_staff'),
    'contact_person'                => post_string('contact_person'),
    'email'                         => post_string('email'),
    'phone'                         => post_string('phone'),
    'contact_gender'                => post_string('contact_gender'),
    'team_positioning'              => post_string('team_positioning'),

    'problem_solution'              => post_string('problem_solution'),
    'primary_users'                 => post_string('primary_users'),
    'solution_languages'            => post_string('solution_languages'),
    'platforms_devices'             => post_string('platforms_devices'),
    'works_offline'                 => post_string('works_offline'),
    'regions_served'                => json_value(clean_scalar_array(post_array('regions_served'))),
    'refugee_settlements_specify'   => post_string('refugee_settlements_specify'),
    'regions_other'                 => post_string('regions_other'),
    'demo_link'                     => post_string('demo_link'),

    'total_users_reached'           => post_string('total_users_reached'),
    'total_learners'                => post_string('total_learners'),
    'active_learners'               => post_string('active_learners'),
    'paying_customers'              => post_string('paying_customers'),
    'partner_organisations'         => post_string('partner_organisations'),
    'revenue_last_12_months'        => post_string('revenue_last_12_months'),
    'other_traction_metrics'        => post_string('other_traction_metrics'),

    'who_pays'                      => post_string('who_pays'),
    'revenue_models'                => json_value(clean_scalar_array(post_array('revenue_models'))),
    'revenue_model_other'           => post_string('revenue_model_other'),
    'revenue_streams'               => post_string('revenue_streams'),
    'raised_external_funding'       => post_string('raised_external_funding'),
    'external_funding_details'      => json_value($externalFundingDetails),
    'fellowship_grant_use'          => post_string('fellowship_grant_use'),
    'fellowship_growth_value'       => post_string('fellowship_growth_value'),

    'has_safeguarding_policy'       => post_string('has_safeguarding_policy'),
    'safeguarding_measures'         => post_string('safeguarding_measures'),
    'has_reporting_procedures'      => post_string('has_reporting_procedures'),
    'reporting_procedures_description' => post_string('reporting_procedures_description'),
    'users_include_minors'          => post_string('users_include_minors'),
    'parental_consent_process'      => post_string('parental_consent_process'),
    'safeguarding_focal_name'       => post_string('safeguarding_focal_name'),
    'safeguarding_focal_role'       => post_string('safeguarding_focal_role'),
    'safeguarding_focal_contact'    => post_string('safeguarding_focal_contact'),
    'accessibility_inclusion'       => post_string('accessibility_inclusion'),

    'impact_measurement'            => post_string('impact_measurement'),
    'data_collection_frequency'     => post_string('data_collection_frequency'),
    'data_uses'                     => json_value(clean_scalar_array(post_array('data_uses'))),
    'data_use_other'                => post_string('data_use_other'),
    'has_mel_framework'             => post_string('has_mel_framework'),
    'data_tools'                    => post_string('data_tools'),
    'learning_outcomes_evidence'    => post_string('learning_outcomes_evidence'),
    'pdpo_status'                   => post_string('pdpo_status'),

    'conflict_of_interest'          => post_string('conflict_of_interest'),
    'conflict_description'          => post_string('conflict_description'),
    'marketing_name_logo_consent'   => post_string('marketing_name_logo_consent'),
    'marketing_content_consent'     => post_string('marketing_content_consent'),
    'data_privacy_consent'          => post_string('data_privacy_consent'),
    'accuracy_declaration'          => post_string('accuracy_declaration'),
];

/*
|--------------------------------------------------------------------------
| CONDITIONAL CLEAN-UP
|--------------------------------------------------------------------------
*/
if (!is_yes($data['has_physical_location'])) {
    $data['district'] = null;
    $data['city_town'] = null;
    $data['physical_address'] = null;
    $data['google_maps_link'] = null;
}

if (!is_yes($data['formally_registered'])) {
    $data['legal_status'] = null;
    $data['legal_status_other'] = null;
    $data['registration_authority'] = null;
    $data['registration_authority_other'] = null;
} else {
    if ($data['legal_status'] !== 'Other') {
        $data['legal_status_other'] = null;
    }

    if ($data['registration_authority'] !== 'Other') {
        $data['registration_authority_other'] = null;
    }
}

if (!is_yes($data['incubation_participated'])) {
    $data['incubation_programmes'] = '[]';
}

if (!is_yes($data['raised_external_funding'])) {
    $data['external_funding_details'] = '[]';
}

if (!is_yes($data['has_reporting_procedures'])) {
    $data['reporting_procedures_description'] = null;
}

if (!is_yes($data['users_include_minors'])) {
    $data['parental_consent_process'] = null;
}

if ($data['conflict_of_interest'] !== 'Yes') {
    $data['conflict_description'] = null;
}

/*
|--------------------------------------------------------------------------
| URL VALIDATION
|--------------------------------------------------------------------------
*/
foreach ([
    'website' => 'Website',
    'google_maps_link' => 'Google Maps link',
    'demo_link' => 'Product demo link',
] as $field => $label) {
    if (
        !empty($data[$field]) &&
        filter_var($data[$field], FILTER_VALIDATE_URL) === false
    ) {
        fail_request("Please enter a valid {$label}.", $opportunityId, $draftId);
    }
}

/*
|--------------------------------------------------------------------------
| FINAL-SUBMISSION VALIDATION
|--------------------------------------------------------------------------
*/
if ($isSubmit) {
    $required = [
        'startup_name' => 'Venture Name',
        'has_physical_location' => 'Physical location status',
        'founded_month' => 'Venture founding month/year',
        'formally_registered' => 'Legal registration status',
        'business_stage' => 'Current venture stage',

        'contact_person' => 'Primary Fellowship contact name',
        'email' => 'Primary Fellowship contact email',
        'phone' => 'Primary Fellowship contact phone',
        'contact_gender' => 'Primary Fellowship contact gender',
        'team_positioning' => 'Team positioning response',

        'problem_solution' => 'Problem and solution response',
        'primary_users' => 'Primary learners/users',
        'solution_languages' => 'Solution languages',
        'platforms_devices' => 'Platforms/devices',
        'works_offline' => 'Offline availability',

        'total_users_reached' => 'Total users reached',
        'total_learners' => 'Total learners',
        'active_learners' => 'Active learners',
        'paying_customers' => 'Paying customers',
        'partner_organisations' => 'Partner organisations',
        'revenue_last_12_months' => 'Revenue in the last 12 months',
        'other_traction_metrics' => 'Other traction metric(s)',

        'who_pays' => 'Who pays for your solution',
        'revenue_streams' => 'Revenue streams',
        'raised_external_funding' => 'External funding status',
        'fellowship_grant_use' => 'Fellowship grant use',
        'fellowship_growth_value' => 'Fellowship growth response',

        'has_safeguarding_policy' => 'Safeguarding policy status',
        'safeguarding_measures' => 'Safeguarding measures',
        'has_reporting_procedures' => 'Safeguarding reporting procedures status',
        'users_include_minors' => 'Minor users status',
        'safeguarding_focal_name' => 'Safeguarding focal point name',
        'safeguarding_focal_role' => 'Safeguarding focal point role',
        'safeguarding_focal_contact' => 'Safeguarding focal point contact',
        'accessibility_inclusion' => 'Accessibility and inclusion response',

        'impact_measurement' => 'Impact measurement response',
        'data_collection_frequency' => 'Data collection frequency',
        'has_mel_framework' => 'MEL framework status',
        'data_tools' => 'Data collection/management tools',
        'learning_outcomes_evidence' => 'Learning outcomes evidence',
        'pdpo_status' => 'PDPO registration status',

        'conflict_of_interest' => 'Conflict of interest declaration',
        'marketing_name_logo_consent' => 'Marketing consent - name and logo',
        'marketing_content_consent' => 'Marketing consent - content/photos/quotes',
        'data_privacy_consent' => 'Data privacy consent',
        'accuracy_declaration' => 'Accuracy declaration',
    ];

    foreach ($required as $field => $label) {
        if ($data[$field] === null || trim((string)$data[$field]) === '') {
            fail_request("{$label} is required.", $opportunityId, $draftId);
        }
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        fail_request('Please enter a valid email address.', $opportunityId, $draftId);
    }

    $requiredJsonSelections = [
        'sector_focus' => 'at least one sector/focus area',
        'heard_about' => 'at least one source for how you heard about the Fellowship',
        'regions_served' => 'at least one region served',
        'revenue_models' => 'at least one revenue model',
        'data_uses' => 'at least one way you use data',
    ];

    foreach ($requiredJsonSelections as $field => $label) {
        $decoded = json_decode((string)$data[$field], true);

        if (!is_array($decoded) || count($decoded) < 1) {
            fail_request("Please select {$label}.", $opportunityId, $draftId);
        }
    }

    if (count($founders) < 1) {
        fail_request('Please add at least one founder.', $opportunityId, $draftId);
    }

    foreach ($founders as $index => $founder) {
        $number = $index + 1;

        foreach ([
            'name' => 'name',
            'role' => 'role',
            'age' => 'age',
            'gender' => 'gender',
            'shareholding' => 'shareholding',
            'full_time' => 'full-time status',
            'pwd_status' => 'PWD status',
        ] as $field => $label) {
            if (($founder[$field] ?? '') === '') {
                fail_request(
                    "Founder {$number}: {$label} is required.",
                    $opportunityId,
                    $draftId
                );
            }
        }

        $age = filter_var($founder['age'], FILTER_VALIDATE_INT);

        if ($age === false || $age < 18 || $age > 100) {
            fail_request(
                "Founder {$number}: please enter a valid age.",
                $opportunityId,
                $draftId
            );
        }

        if (!is_numeric($founder['shareholding'])) {
            fail_request(
                "Founder {$number}: shareholding must be numeric.",
                $opportunityId,
                $draftId
            );
        }

        $shareholding = (float)$founder['shareholding'];

        if ($shareholding < 0 || $shareholding > 100) {
            fail_request(
                "Founder {$number}: shareholding must be between 0 and 100.",
                $opportunityId,
                $draftId
            );
        }
    }

    $totalShareholding = array_sum(
        array_map(
            static fn(array $founder): float =>
                is_numeric($founder['shareholding'] ?? null)
                    ? (float)$founder['shareholding']
                    : 0.0,
            $founders
        )
    );

    if ($totalShareholding > 100.01) {
        fail_request(
            'Total founder shareholding cannot exceed 100%.',
            $opportunityId,
            $draftId
        );
    }

    if ($data['formally_registered'] === 'Yes') {
        if (empty($data['legal_status'])) {
            fail_request(
                'Legal status is required for a formally registered venture.',
                $opportunityId,
                $draftId
            );
        }

        if ($data['legal_status'] === 'Other' && empty($data['legal_status_other'])) {
            fail_request(
                'Please specify the venture legal status.',
                $opportunityId,
                $draftId
            );
        }

        if (empty($data['registration_authority'])) {
            fail_request(
                'Registration authority is required for a formally registered venture.',
                $opportunityId,
                $draftId
            );
        }

        if (
            $data['registration_authority'] === 'Other' &&
            empty($data['registration_authority_other'])
        ) {
            fail_request(
                'Please specify the registration authority.',
                $opportunityId,
                $draftId
            );
        }
    }

    if (
        $data['incubation_participated'] === 'Yes' &&
        count($incubationProgrammes) < 1
    ) {
        fail_request(
            'Please add at least one incubation, acceleration or fellowship programme.',
            $opportunityId,
            $draftId
        );
    }

    if (
        $data['raised_external_funding'] === 'Yes' &&
        count($externalFundingDetails) < 1
    ) {
        fail_request(
            'Please add details of at least one previous investor/funder.',
            $opportunityId,
            $draftId
        );
    }

    if (
        $data['has_reporting_procedures'] === 'Yes' &&
        empty($data['reporting_procedures_description'])
    ) {
        fail_request(
            'Please describe your safeguarding reporting and response procedures.',
            $opportunityId,
            $draftId
        );
    }

    if (
        $data['users_include_minors'] === 'Yes' &&
        empty($data['parental_consent_process'])
    ) {
        fail_request(
            'Please describe how parental/guardian consent is obtained.',
            $opportunityId,
            $draftId
        );
    }

    if (
        $data['conflict_of_interest'] === 'Yes' &&
        empty($data['conflict_description'])
    ) {
        fail_request(
            'Please describe the declared conflict of interest.',
            $opportunityId,
            $draftId
        );
    }

    if (
        $data['data_privacy_consent'] !== 'Yes' ||
        $data['accuracy_declaration'] !== 'Yes'
    ) {
        fail_request(
            'You must accept the data privacy and accuracy declarations before submitting.',
            $opportunityId,
            $draftId
        );
    }

    validate_word_limit($data, 'team_positioning', 'Team positioning', 250, $opportunityId, $draftId);
    validate_word_limit($data, 'problem_solution', 'Problem and solution response', 500, $opportunityId, $draftId);
    validate_word_limit($data, 'fellowship_grant_use', 'Fellowship grant use', 300, $opportunityId, $draftId);
    validate_word_limit($data, 'fellowship_growth_value', 'Fellowship growth response', 250, $opportunityId, $draftId);
    validate_word_limit($data, 'safeguarding_measures', 'Safeguarding measures', 300, $opportunityId, $draftId);
    validate_word_limit($data, 'parental_consent_process', 'Parental/guardian consent response', 150, $opportunityId, $draftId);
    validate_word_limit($data, 'accessibility_inclusion', 'Accessibility and inclusion response', 300, $opportunityId, $draftId);
    validate_word_limit($data, 'impact_measurement', 'Impact measurement response', 300, $opportunityId, $draftId);
    validate_word_limit($data, 'learning_outcomes_evidence', 'Learning outcomes evidence', 150, $opportunityId, $draftId);
}

/*
|--------------------------------------------------------------------------
| APPLICANT ACCOUNT
|--------------------------------------------------------------------------
*/
function generate_temp_password(int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#';
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $password;
}

function get_or_create_user(mysqli $conn, string $email, string $fullName): array
{
    $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');

    if (!$stmt) {
        throw new RuntimeException('Unable to check applicant account.');
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        return [
            'user_id' => (int)$existing['user_id'],
            'is_new' => false,
            'temp_password' => null,
        ];
    }

    $tempPassword = generate_temp_password();
    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users (
            username,
            email,
            password_hash,
            full_name,
            role,
            is_active
        ) VALUES (?, ?, ?, ?, 'Applicant', 1)
    ");

    if (!$stmt) {
        throw new RuntimeException('Unable to create applicant account.');
    }

    $stmt->bind_param(
        'ssss',
        $email,
        $email,
        $passwordHash,
        $fullName
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $userId = (int)$stmt->insert_id;
    $stmt->close();

    return [
        'user_id' => $userId,
        'is_new' => true,
        'temp_password' => $tempPassword,
    ];
}

$sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if (!$isSubmit && empty($data['email']) && $sessionUserId < 1) {
    fail_request(
        'Please complete the Primary Fellowship Contact email before saving your first draft.',
        $opportunityId,
        $draftId
    );
}

if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    fail_request(
        'Please enter a valid email address.',
        $opportunityId,
        $draftId
    );
}

$userInfo = [
    'user_id' => $sessionUserId,
    'is_new' => false,
    'temp_password' => null,
];

try {
    // A logged-in applicant always acts as themselves; the email field is
    // only the application's contact address, never an account selector.
    if ($sessionUserId > 0) {
        // keep $userInfo for the session user
    } elseif (!empty($data['email'])) {
        $userInfo = get_or_create_user(
            $conn,
            $data['email'],
            $data['contact_person'] ?: $data['startup_name'] ?: 'Applicant'
        );
    } elseif ($sessionUserId < 1) {
        fail_request(
            'Unable to identify the applicant account.',
            $opportunityId,
            $draftId
        );
    }
} catch (Throwable $e) {
    error_log('Applicant account error: ' . $e->getMessage());

    fail_request(
        'We could not create or locate your applicant account. Please try again.',
        $opportunityId,
        $draftId
    );
}

$submittedBy = (int)$userInfo['user_id'];

// Anonymous visitors may not file under an existing account just by typing
// its email address: they must log in first.
if ($sessionUserId < 1 && !$userInfo['is_new']) {
    fail_request(
        'An account with this email already exists. Please log in to continue your application.',
        $opportunityId,
        $draftId
    );
}

if ($userInfo['is_new'] && $sessionUserId < 1) {
    session_regenerate_id(true); // new authenticated session: prevent fixation
    $_SESSION['user_id'] = $submittedBy;
    $_SESSION['user_email'] = $data['email'];
    $_SESSION['user_name'] = $data['contact_person'] ?: $data['startup_name'];
    $_SESSION['user_role'] = 'Applicant';
    $_SESSION['role'] = 'Applicant';
}

/*
|--------------------------------------------------------------------------
| DRAFT OWNERSHIP
|--------------------------------------------------------------------------
*/
$existingApplication = null;

if ($draftId > 0) {
    $stmt = $conn->prepare("
        SELECT
            application_id,
            opportunity_id,
            submitted_by,
            status,
            email,
            legal_docs_path,
            safeguarding_policy_path
        FROM applications
        WHERE application_id = ?
          AND opportunity_id = ?
          AND status = 'Draft'
        LIMIT 1
    ");

    if (!$stmt) {
        fail_request('Unable to load the saved draft.', $opportunityId, $draftId);
    }

    $stmt->bind_param('ii', $draftId, $opportunityId);
    $stmt->execute();
    $existingApplication = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existingApplication) {
        fail_request(
            'The saved draft could not be found or is no longer editable.',
            $opportunityId
        );
    }

    $ownerId = (int)($existingApplication['submitted_by'] ?? 0);

    if ($ownerId !== $submittedBy) {
        fail_request(
            'You do not have permission to edit this draft.',
            $opportunityId
        );
    }
}

/*
|--------------------------------------------------------------------------
| CAPACITY
|--------------------------------------------------------------------------
*/
if ($isSubmit && !empty($opportunity['max_applicants'])) {
    $maxApplicants = (int)$opportunity['max_applicants'];

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM applications
        WHERE opportunity_id = ?
          AND status <> 'Draft'
    ");

    $stmt->bind_param('i', $opportunityId);
    $stmt->execute();
    $totalSubmitted = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($totalSubmitted >= $maxApplicants) {
        fail_request(
            'This opportunity has reached the maximum number of applicants.',
            $opportunityId,
            $draftId
        );
    }
}

/*
|--------------------------------------------------------------------------
| UPLOADS
|--------------------------------------------------------------------------
*/
function upload_application_file(array $file, int $opportunityId, string $type): array
{
    $rules = [
        'legal_docs' => [
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'zip'],
            'mimes' => [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ],
        ],
        'safeguarding_policy' => [
            'extensions' => ['pdf', 'doc', 'docx'],
            'mimes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/octet-stream',
            ],
        ],
    ];

    if (!isset($rules[$type])) {
        return ['success' => false, 'error' => 'Unsupported upload type.'];
    }

    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK ||
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        return ['success' => false, 'error' => 'The uploaded file could not be processed.'];
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Uploaded files must not exceed 10 MB.'];
    }

    $extension = strtolower(
        pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)
    );

    if (!in_array($extension, $rules[$type]['extensions'], true)) {
        return ['success' => false, 'error' => "Invalid file type for {$type}."];
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: null;

        if ($mime !== null && !in_array($mime, $rules[$type]['mimes'], true)) {
            return ['success' => false, 'error' => "The uploaded {$type} file format is not allowed."];
        }
    }

    $relativeDir = 'uploads/applications/' . $opportunityId . '/';
    $filesystemDir = dirname(__DIR__) . '/' . $relativeDir;

    if (
        !is_dir($filesystemDir) &&
        !mkdir($filesystemDir, 0755, true) &&
        !is_dir($filesystemDir)
    ) {
        return ['success' => false, 'error' => 'Unable to create the application upload folder.'];
    }

    $filename =
        $type . '_' .
        date('Ymd_His') . '_' .
        bin2hex(random_bytes(8)) . '.' .
        $extension;

    $destination = $filesystemDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => "Failed to upload {$type}."];
    }

    return [
        'success' => true,
        'path' => $relativeDir . $filename,
    ];
}

$legalDocsPath = $existingApplication['legal_docs_path'] ?? null;
$safeguardingPolicyPath = $existingApplication['safeguarding_policy_path'] ?? null;

if (!empty($_FILES['legal_docs']['name'])) {
    $upload = upload_application_file(
        $_FILES['legal_docs'],
        $opportunityId,
        'legal_docs'
    );

    if (!$upload['success']) {
        fail_request($upload['error'], $opportunityId, $draftId);
    }

    $legalDocsPath = $upload['path'];
}

if (!empty($_FILES['safeguarding_policy']['name'])) {
    $upload = upload_application_file(
        $_FILES['safeguarding_policy'],
        $opportunityId,
        'safeguarding_policy'
    );

    if (!$upload['success']) {
        fail_request($upload['error'], $opportunityId, $draftId);
    }

    $safeguardingPolicyPath = $upload['path'];
}

$data['legal_docs_path'] = $legalDocsPath;
$data['safeguarding_policy_path'] = $safeguardingPolicyPath;

if (
    $isSubmit &&
    $data['has_safeguarding_policy'] === 'Yes' &&
    empty($data['safeguarding_policy_path'])
) {
    fail_request(
        'Please upload your Gender, Safeguarding or Child Protection Policy.',
        $opportunityId,
        $draftId
    );
}

/*
|--------------------------------------------------------------------------
| DYNAMIC PREPARED SAVE
|--------------------------------------------------------------------------
*/
function bind_dynamic(mysqli_stmt $stmt, array &$values): void
{
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

function insert_application(
    mysqli $conn,
    int $opportunityId,
    int $submittedBy,
    array $data,
    string $status,
    ?string $submittedAt
): int {
    $fields = array_merge(
        [
            'opportunity_id' => $opportunityId,
            'submitted_by' => $submittedBy,
        ],
        $data,
        [
            'status' => $status,
            'submitted_at' => $submittedAt,
            'last_saved_at' => date('Y-m-d H:i:s'),
        ]
    );

    $columns = array_keys($fields);
    $values = array_values($fields);
    $quotedColumns = array_map(
        static fn(string $column): string => "`{$column}`",
        $columns
    );
    $placeholders = array_fill(0, count($columns), '?');

    $sql =
        'INSERT INTO applications (' .
        implode(', ', $quotedColumns) .
        ') VALUES (' .
        implode(', ', $placeholders) .
        ')';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare application insert: ' . $conn->error);
    }

    bind_dynamic($stmt, $values);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $applicationId = (int)$stmt->insert_id;
    $stmt->close();

    return $applicationId;
}

function update_application(
    mysqli $conn,
    int $applicationId,
    int $opportunityId,
    int $submittedBy,
    array $data,
    string $status,
    ?string $submittedAt,
    ?string $referenceNumber
): void {
    $fields = $data;

    $fields['submitted_by'] = $submittedBy;
    $fields['status'] = $status;
    $fields['last_saved_at'] = date('Y-m-d H:i:s');

    if ($submittedAt !== null) {
        $fields['submitted_at'] = $submittedAt;
    }

    if ($referenceNumber !== null) {
        $fields['reference_number'] = $referenceNumber;
    }

    $set = [];
    $values = [];

    foreach ($fields as $column => $value) {
        $set[] = "`{$column}` = ?";
        $values[] = $value;
    }

    $values[] = $applicationId;
    $values[] = $opportunityId;

    $sql = "
        UPDATE applications
        SET " . implode(', ', $set) . "
        WHERE application_id = ?
          AND opportunity_id = ?
          AND status = 'Draft'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare application update: ' . $conn->error);
    }

    bind_dynamic($stmt, $values);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $stmt->close();
}

function set_reference_number(
    mysqli $conn,
    int $applicationId,
    string $referenceNumber
): void {
    $stmt = $conn->prepare("
        UPDATE applications
        SET reference_number = ?
        WHERE application_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Unable to update application reference.');
    }

    $stmt->bind_param('si', $referenceNumber, $applicationId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $stmt->close();
}

function log_status_history(
    mysqli $conn,
    int $applicationId,
    ?string $oldStatus,
    string $newStatus,
    int $changedBy,
    string $comment
): void {
    $stmt = $conn->prepare("
        INSERT INTO application_status_history (
            application_id,
            old_status,
            new_status,
            changed_by,
            comments
        ) VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log('Application status history prepare failed: ' . $conn->error);
        return;
    }

    $stmt->bind_param(
        'issis',
        $applicationId,
        $oldStatus,
        $newStatus,
        $changedBy,
        $comment
    );

    if (!$stmt->execute()) {
        error_log('Application status history insert failed: ' . $stmt->error);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/
$status = $isSubmit ? 'Submitted' : 'Draft';
$submittedAt = $isSubmit ? date('Y-m-d H:i:s') : null;
$oldStatus = $draftId > 0 ? 'Draft' : null;

try {
    $conn->begin_transaction();

    if ($draftId > 0) {
        $applicationId = $draftId;

        $reference = $isSubmit
            ? 'APP-' . str_pad((string)$applicationId, 6, '0', STR_PAD_LEFT)
            : null;

        update_application(
            $conn,
            $applicationId,
            $opportunityId,
            $submittedBy,
            $data,
            $status,
            $submittedAt,
            $reference
        );
    } else {
        $applicationId = insert_application(
            $conn,
            $opportunityId,
            $submittedBy,
            $data,
            $status,
            $submittedAt
        );

        if ($isSubmit) {
            $reference =
                'APP-' .
                str_pad((string)$applicationId, 6, '0', STR_PAD_LEFT);

            set_reference_number(
                $conn,
                $applicationId,
                $reference
            );
        }
    }

    log_status_history(
        $conn,
        $applicationId,
        $oldStatus,
        $status,
        $submittedBy,
        $isSubmit ? 'Application submitted' : 'Draft saved'
    );

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();

    error_log(
        'Application save failed. Opportunity=' . $opportunityId .
        ', Draft=' . $draftId .
        ', Error=' . $e->getMessage()
    );

    fail_request(
        'We could not save your application. Please try again.',
        $opportunityId,
        $draftId
    );
}

/*
|--------------------------------------------------------------------------
| EMAILS
|--------------------------------------------------------------------------
*/
if (!$isSubmit) {
    clear_application_old_input();

    try {
        if (!empty($data['email'])) {
            if (
                $userInfo['is_new'] &&
                !empty($userInfo['temp_password']) &&
                function_exists('send_draft_saved_email')
            ) {
                send_draft_saved_email(
                    $data['email'],
                    $data['contact_person'] ?: $data['startup_name'] ?: 'Applicant',
                    $data['startup_name'] ?: 'Your venture',
                    $applicationId,
                    $userInfo['temp_password']
                );
            } elseif (function_exists('send_draft_updated_email')) {
                send_draft_updated_email(
                    $data['email'],
                    $data['contact_person'] ?: $data['startup_name'] ?: 'Applicant',
                    $data['startup_name'] ?: 'Your venture',
                    $applicationId
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Draft email failed: ' . $e->getMessage());
    }

    $_SESSION['draft_saved'] = true;
    $_SESSION['draft_id'] = $applicationId;
    $_SESSION['draft_startup'] = $data['startup_name'] ?? '';
    $_SESSION['draft_email'] = $data['email'] ?? '';
    $_SESSION['draft_is_new_user'] = $userInfo['is_new'];
    $_SESSION['draft_temp_pass'] = $userInfo['temp_password'];

    header('Location: /draft-saved');
    exit;
}

try {
    if (
        !empty($data['email']) &&
        function_exists('send_application_confirmation')
    ) {
        send_application_confirmation(
            $data['email'],
            $data['startup_name'] ?? 'Your venture',
            $applicationId
        );
    }
} catch (Throwable $e) {
    error_log('Application confirmation email failed: ' . $e->getMessage());
}

clear_application_old_input();

$_SESSION['success'] = 'Application submitted successfully.';
$_SESSION['application_id'] = $applicationId;

header('Location: /application-success?id=' . $applicationId);
exit;
