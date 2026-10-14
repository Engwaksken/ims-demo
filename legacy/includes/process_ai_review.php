<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AiReviewService.php';

function redirectBack(
    string $type,
    string $message,
    int $applicationId,
    int $reviewTypeId = 0
): never {
    $_SESSION[$type] = $message;

    $url = '../ai-review-applicant?id=' . $applicationId;

    if ($reviewTypeId > 0) {
        $url .= '&rt=' . $reviewTypeId;
    }

    header('Location: ' . $url);
    exit;
}

function safePostInt(string $key): int
{
    return (int)(filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: 0);
}

function getApiLabel(mysqli $conn, int $aiApiId): array
{
    if ($aiApiId <= 0) {
        $sql = "
            SELECT ai_api_id, provider_name, api_name, model_name, is_active
            FROM ai_api
            WHERE is_active = 1
            ORDER BY is_default DESC, ai_api_id DESC
            LIMIT 1
        ";

        $res = $conn->query($sql);
        $row = $res ? $res->fetch_assoc() : null;

        return $row ?: [
            'ai_api_id' => 0,
            'provider_name' => 'Unknown',
            'api_name' => 'Default AI',
            'model_name' => '',
            'is_active' => 0
        ];
    }

    $stmt = $conn->prepare("
        SELECT ai_api_id, provider_name, api_name, model_name, is_active
        FROM ai_api
        WHERE ai_api_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $aiApiId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: [
        'ai_api_id' => $aiApiId,
        'provider_name' => 'Unknown',
        'api_name' => 'Selected AI',
        'model_name' => '',
        'is_active' => 0
    ];
}

function friendlyAiError(string $rawMessage, array $api): string
{
    $msg = strtolower($rawMessage);
    $provider = strtolower((string)($api['provider_name'] ?? ''));
    $apiName = (string)($api['api_name'] ?? 'Selected AI');
    $model = (string)($api['model_name'] ?? '');

    $label = trim($apiName . ($model !== '' ? ' / ' . $model : ''));

    if (str_contains($msg, 'insufficient_quota')) {
        return $label . ': OpenAI API quota/billing is not active. ChatGPT Plus does not include API credits. Add API billing or select Gemini.';
    }

    if (str_contains($msg, 'api_key_invalid') || str_contains($msg, 'api key not valid')) {
        return $label . ': API key is invalid. Edit this provider in Manage AI APIs and paste a fresh valid key.';
    }

    if (str_contains($msg, 'permission_denied') || str_contains($msg, 'unregistered callers')) {
        return $label . ': API key was not sent or endpoint is missing the key. For Gemini, leave endpoint blank or ensure AiReviewService appends ?key= automatically.';
    }

    if (str_contains($msg, 'not_found') || str_contains($msg, 'is not found for api version')) {
        return $label . ': model is not available for this API version. For Gemini use gemini-2.0-flash and leave endpoint blank.';
    }

    if (str_contains($msg, 'failed to decrypt api key')) {
        return $label . ': failed to decrypt API key. Check that APP_KEY in config.php has not changed, then re-save the API key.';
    }

    if (str_contains($msg, 'invalid stored api key')) {
        return $label . ': stored API key is invalid/corrupted. Re-enter the API key from Manage AI APIs.';
    }

    if (str_contains($msg, 'ai returned invalid json')) {
        return $label . ': AI returned invalid JSON. Reduce max tokens, retry, or improve the prompt to return JSON only.';
    }

    if (str_contains($msg, 'no active ai api configured')) {
        return 'No active AI API is configured. Activate Gemini or OpenAI in Manage AI APIs.';
    }

    if (str_contains($msg, 'no active review criteria')) {
        return 'No active review criteria found for this review type.';
    }

    if (str_contains($msg, 'application not found')) {
        return 'Application record no longer exists.';
    }

    if (str_contains($msg, '429')) {
        if (
            str_contains($provider, 'gemini') ||
            str_contains($provider, 'google')
        ) {
            return $label . ': Gemini rate limit or free-tier quota has been reached. Wait a few minutes, reduce max tokens, or use another Gemini key.';
        }

        if (str_contains($provider, 'openai')) {
            return $label . ': OpenAI rate limit/quota reached. Check API billing/credits or wait and retry.';
        }

        return $label . ': AI provider rate limit or quota reached. Wait and retry.';
    }

    if (str_contains($msg, '400')) {
        return $label . ': bad request. Check model name, endpoint, and request format.';
    }

    if (str_contains($msg, '401')) {
        return $label . ': unauthorized. Check the API key.';
    }

    if (str_contains($msg, '403')) {
        return $label . ': permission denied. Check API key restrictions, endpoint, and whether the API is enabled.';
    }

    if (str_contains($msg, '404')) {
        return $label . ': endpoint or model not found. Check model name and endpoint.';
    }

    if (str_contains($msg, '500') || str_contains($msg, '503')) {
        return $label . ': provider service is temporarily unavailable. Retry later.';
    }

    return $rawMessage;
}

/* =========================================================
   ACCESS CONTROL
========================================================= */
if (!ims_allowed('ai_review.run', ['Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin', 'Project Officer', 'Reviewer'], 'Run AI reviews on applications')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ../dashboard');
    exit;
}

/* =========================================================
   REQUEST VALIDATION
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../startups-shortlisting');
    exit;
}

$userId        = (int)$_SESSION['user_id'];
$applicationId = safePostInt('application_id');
$reviewTypeId  = safePostInt('review_type_id');
$aiApiId       = safePostInt('ai_api_id');

if ($applicationId <= 0) {
    $_SESSION['error'] = 'Invalid application selected.';
    header('Location: ../startups-shortlisting');
    exit;
}

if ($reviewTypeId <= 0) {
    redirectBack('error', 'Invalid review type selected.', $applicationId);
}

/* =========================================================
   VERIFY APPLICATION EXISTS
========================================================= */
$stmt = $conn->prepare("
    SELECT
        application_id,
        startup_name,
        opportunity_id,
        review_type_id,
        status
    FROM applications
    WHERE application_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $applicationId);
$stmt->execute();
$application = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$application) {
    redirectBack('error', 'Application not found.', $applicationId, $reviewTypeId);
}

/* =========================================================
   VERIFY AI API
========================================================= */
$api = getApiLabel($conn, $aiApiId);

if (empty($api['ai_api_id'])) {
    redirectBack('error', 'No active AI API is configured.', $applicationId, $reviewTypeId);
}

if ((int)$api['is_active'] !== 1) {
    redirectBack('error', 'Selected AI API is inactive.', $applicationId, $reviewTypeId);
}

/* =========================================================
   GENERATE AI REVIEW
========================================================= */
try {
    $service = new AiReviewService($conn);

    $service->generateReview(
        $applicationId,
        $reviewTypeId,
        $userId,
        $aiApiId > 0 ? $aiApiId : null
    );

    $stmt = $conn->prepare("
        UPDATE applications
        SET
            status = CASE
                WHEN status = 'Submitted'
                THEN 'Under Review'
                ELSE status
            END,
            updated_at = NOW()
        WHERE application_id = ?
    ");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $stmt->close();

    redirectBack(
        'success',
       'AI review generated successfully using ' . ($api['api_name'] ?? 'AI') . '.',
        $applicationId,
        $reviewTypeId
    );

} catch (Throwable $e) {
    $raw = $e->getMessage();

    error_log(
        '[AI REVIEW ERROR] ' . $raw
        . ' | application_id=' . $applicationId
        . ' | review_type_id=' . $reviewTypeId
        . ' | ai_api_id=' . (int)($api['ai_api_id'] ?? 0)
        . ' | provider=' . ($api['provider_name'] ?? 'unknown')
        . ' | user_id=' . $userId
    );

    redirectBack(
        'error',
        'AI review failed: ' . friendlyAiError($raw, $api),
        $applicationId,
        $reviewTypeId
    );
}