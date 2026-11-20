<?php
declare(strict_types=1);

final class AiReviewService
{
    private mysqli $conn;
    private string $encryptionKey;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->encryptionKey = defined('APP_KEY') ? (string)APP_KEY : hash('sha256', __DIR__ . php_uname());
    }

    public function encryptKey(string $plain): string
    {
        // Preferred: shared key from .env (DATA_ENCRYPTION_KEY / APP_KEY) via
        // security.php. Falls back to the legacy machine-derived key only
        // when no .env key is configured.
        if (function_exists('ims_encrypt') && function_exists('ims_encryption_key') && ims_encryption_key() !== null) {
            $enc = ims_encrypt($plain);
            if (function_exists('ims_is_encrypted') && ims_is_encrypted($enc)) {
                return $enc;
            }
        }

        $iv = random_bytes(16);
        $key = hash('sha256', $this->encryptionKey, true);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt API key.');
        }
        return base64_encode($iv . $cipher);
    }

    public function decryptKey(string $encrypted): string
    {
        if (function_exists('ims_is_encrypted') && ims_is_encrypted($encrypted)) {
            $plain = ims_decrypt($encrypted);
            if ($plain === '') {
                throw new RuntimeException('Failed to decrypt API key.');
            }
            return $plain;
        }

        // Legacy format (AES-256-CBC with machine-derived key).
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= 16) {
            throw new RuntimeException('Invalid stored API key.');
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key = hash('sha256', $this->encryptionKey, true);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt API key.');
        }
        return $plain;
    }

    public function getDefaultApi(): array
    {
        $sql = "SELECT * FROM ai_api WHERE is_active = 1 ORDER BY is_default DESC, ai_api_id DESC LIMIT 1";
        $res = $this->conn->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            throw new RuntimeException('No active AI API configured.');
        }
        return $row;
    }

    /**
     * One prompt in, one decoded JSON object out, using the active (default)
     * AI API unless $aiApiId names another active one. Used by HR candidate
     * screening (includes/hr.php).
     *
     * $files: [['name' => 'cv.pdf', 'mime' => 'application/pdf', 'data' => <raw bytes>], ...]
     * sent natively to providers that read documents (Gemini, OpenAI);
     * other providers get the prompt only, so callers should also put any
     * extracted text in the prompt.
     *
     * @return array{data: array, api: array, raw: string}
     */
    public function completeJson(string $prompt, array $files = [], ?int $aiApiId = null): array
    {
        $api = $aiApiId ? $this->fetchApi($aiApiId) : $this->getDefaultApi();
        $raw = $this->callProvider($api, $prompt, $files);

        return ['data' => $this->extractJson($raw), 'api' => $api, 'raw' => $raw];
    }

    public function generateReview(int $applicationId, int $reviewTypeId, int $requestedBy, ?int $aiApiId = null): int
    {
        $api = $aiApiId ? $this->fetchApi($aiApiId) : $this->getDefaultApi();
        $payload = $this->buildReviewPayload($applicationId, $reviewTypeId);
        $prompt = $this->buildPrompt($payload);
      $hash = hash('sha256', $prompt);

/* initialize before passing by reference */
$pendingId = 0;

$this->insertPending(
    $applicationId,
    (int)($payload['application']['opportunity_id'] ?? 0),
    $reviewTypeId,
    (int)$api['ai_api_id'],
    $requestedBy,
    $hash,
    $pendingId
);
        try {
            $raw = $this->callProvider($api, $prompt);
            $parsed = $this->extractJson($raw);
            $aiReviewId = $this->saveCompletedReview($pendingId, $applicationId, $reviewTypeId, (int)$api['ai_api_id'], $requestedBy, $parsed, $raw, $payload);
            return $aiReviewId;
        } catch (Throwable $e) {
            $stmt = $this->conn->prepare("UPDATE ai_application_reviews SET status='Failed', error_message=?, updated_at=NOW() WHERE ai_review_id=?");
            $msg = $e->getMessage();
            $stmt->bind_param('si', $msg, $pendingId);
            $stmt->execute();
            $stmt->close();
            throw $e;
        }
    }

    private function fetchApi(int $id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM ai_api WHERE ai_api_id=? AND is_active=1 LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException('Selected AI API is not active or does not exist.');
        return $row;
    }

    private function buildReviewPayload(int $applicationId, int $reviewTypeId): array
    {
        $stmt = $this->conn->prepare("SELECT a.*, ao.opportunity_title, ao.opportunity_type, ao.eligibility_criteria, ao.required_documents, ao.sectors_allowed, ao.min_team_size, ao.max_team_size FROM applications a JOIN application_opportunities ao ON ao.opportunity_id=a.opportunity_id WHERE a.application_id=? LIMIT 1");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$app) throw new RuntimeException('Application not found.');

        $stmt = $this->conn->prepare("SELECT criteria_id, category, question, description, max_score, weight FROM review_criteria WHERE review_type_id=? AND is_active=1 ORDER BY category, criteria_id");
        $stmt->bind_param('i', $reviewTypeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $criteria = [];
        while ($row = $res->fetch_assoc()) $criteria[] = $row;
        $stmt->close();
        if (!$criteria) throw new RuntimeException('No active review criteria found for this review type.');

        return ['application' => $app, 'criteria' => $criteria];
    }

    private function buildPrompt(array $payload): string
    {
        $app = $payload['application'];
        $criteria = $payload['criteria'];

        $safeApp = [];
        foreach ($app as $k => $v) {
            if (in_array($k, ['legal_docs_path','business_plan_path','pitch_deck_path'], true)) continue;
          $safeApp[$k] = is_string($v) ? mb_substr($v, 0, 300) : $v;
        }

        return "You are an impartial startup grant/accelerator application reviewer. Score only from the supplied application data. Do not invent facts. Return STRICT JSON only with this schema:\n" .
            '{"overall_score":0-100,"recommendation":"Accept|Reject|Shortlist|Hold","summary":"...","strengths":"...","weaknesses":"...","risks":"...","suggested_questions":"...","criteria_scores":[{"criteria_id":1,"score":0,"comments":"..."}]}' .
            "\nRules: score must not exceed each criterion max_score. Use the criterion weight when deciding overall_score. Be fair, concise, and evidence-based.\n\nAPPLICATION:\n" .
            json_encode($safeApp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
            "\n\nCRITERIA:\n" . json_encode($criteria, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function callProvider(array $api, string $prompt, array $files = []): string
    {
        $provider = strtolower((string)$api['provider_name']);
        $key = $this->decryptKey((string)$api['api_key_encrypted']);
        $model = (string)$api['model_name'];
        $timeout = (int)$api['timeout_seconds'];
        $temperature = (float)$api['temperature'];
        $maxTokens = (int)$api['max_tokens'];

        if (str_contains($provider, 'gemini') || str_contains($provider, 'google')) {
          $url = trim((string)($api['api_endpoint'] ?? ''));

if ($url === '') {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
}

if (!str_contains($url, '?key=')) {
    $url .= (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($key);
}
            $parts = [['text' => $prompt]];
            foreach ($files as $file) {
                $parts[] = ['inline_data' => ['mime_type' => (string)$file['mime'], 'data' => base64_encode((string)$file['data'])]];
            }
            $body = [
                'contents' => [['parts' => $parts]],
                'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json']
            ];
            $headers = ['Content-Type: application/json'];
        } else {
            $url = $api['api_endpoint'] ?: 'https://api.openai.com/v1/chat/completions';
            $userContent = $prompt;
            // Only OpenAI's own endpoint is known to accept PDF file parts;
            // other OpenAI-compatible endpoints get the text prompt alone.
            if ($files && str_contains((string)$url, 'api.openai.com')) {
                $userContent = [['type' => 'text', 'text' => $prompt]];
                foreach ($files as $file) {
                    if ((string)$file['mime'] !== 'application/pdf') {
                        continue;
                    }
                    $userContent[] = ['type' => 'file', 'file' => [
                        'filename' => (string)($file['name'] ?? 'document.pdf'),
                        'file_data' => 'data:application/pdf;base64,' . base64_encode((string)$file['data']),
                    ]];
                }
            }
            $body = [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => 'Return valid JSON only.'],
                    ['role' => 'user', 'content' => $userContent]
                ]
            ];
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('AI request failed. HTTP ' . $code . ' ' . $err . ' ' . mb_substr((string)$response, 0, 500));
        }

        $json = json_decode($response, true);
        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            return (string)$json['candidates'][0]['content']['parts'][0]['text'];
        }
        if (isset($json['choices'][0]['message']['content'])) {
            return (string)$json['choices'][0]['message']['content'];
        }
        return $response;
    }

    private function extractJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```json\s*|```$/m', '', $raw) ?? $raw;
        $data = json_decode(trim($raw), true);
        if (!is_array($data)) {
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        if (!is_array($data)) throw new RuntimeException('AI returned invalid JSON.');
        return $data;
    }

    private function insertPending(int $applicationId, int $opportunityId, int $reviewTypeId, int $apiId, int $requestedBy, string $hash, int &$id): void
    {
        $stmt = $this->conn->prepare("INSERT INTO ai_application_reviews (application_id, opportunity_id, review_type_id, ai_api_id, requested_by, prompt_hash, status) VALUES (?,?,?,?,?,?,'Pending')");
        $stmt->bind_param('iiiiis', $applicationId, $opportunityId, $reviewTypeId, $apiId, $requestedBy, $hash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
    }

    private function saveCompletedReview(int $aiReviewId, int $applicationId, int $reviewTypeId, int $apiId, int $requestedBy, array $parsed, string $raw, array $payload): int
    {
        $criteriaById = [];
        $totalMaxWeighted = 0.0;
        foreach ($payload['criteria'] as $c) {
            $cid = (int)$c['criteria_id'];
            $criteriaById[$cid] = $c;
            $totalMaxWeighted += ((float)$c['max_score'] * (float)$c['weight']);
        }

        $weighted = 0.0;
        foreach (($parsed['criteria_scores'] ?? []) as $s) {
            $cid = (int)($s['criteria_id'] ?? 0);
            if (!isset($criteriaById[$cid])) continue;
            $max = (float)$criteriaById[$cid]['max_score'];
            $weight = (float)$criteriaById[$cid]['weight'];
            $score = max(0, min($max, (float)($s['score'] ?? 0)));
            $weighted += $score * $weight;
        }
        $overall = $totalMaxWeighted > 0 ? round(($weighted / $totalMaxWeighted) * 100, 2) : (float)($parsed['overall_score'] ?? 0);
        $rec = in_array(($parsed['recommendation'] ?? ''), ['Accept','Reject','Shortlist','Hold'], true) ? $parsed['recommendation'] : 'Hold';
        $summary = (string)($parsed['summary'] ?? '');
        $strengths = (string)($parsed['strengths'] ?? '');
        $weaknesses = (string)($parsed['weaknesses'] ?? '');
        $risks = (string)($parsed['risks'] ?? '');
        $questions = (string)($parsed['suggested_questions'] ?? '');

        $this->conn->begin_transaction();
        $stmt = $this->conn->prepare("UPDATE ai_application_reviews SET overall_score=?, recommendation=?, summary=?, strengths=?, weaknesses=?, risks=?, suggested_questions=?, raw_response=?, status='Completed', updated_at=NOW() WHERE ai_review_id=?");
        $stmt->bind_param('dsssssssi', $overall, $rec, $summary, $strengths, $weaknesses, $risks, $questions, $raw, $aiReviewId);
        $stmt->execute();
        $stmt->close();

        $ins = $this->conn->prepare("INSERT INTO ai_application_review_scores (ai_review_id, application_id, review_type_id, criteria_id, score, max_score, weight, comments) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), max_score=VALUES(max_score), weight=VALUES(weight), comments=VALUES(comments)");
        foreach (($parsed['criteria_scores'] ?? []) as $s) {
            $cid = (int)($s['criteria_id'] ?? 0);
            if (!isset($criteriaById[$cid])) continue;
            $max = (float)$criteriaById[$cid]['max_score'];
            $weight = (float)$criteriaById[$cid]['weight'];
            $score = max(0, min($max, (float)($s['score'] ?? 0)));
            $comments = (string)($s['comments'] ?? '');
            $ins->bind_param('iiiiddds', $aiReviewId, $applicationId, $reviewTypeId, $cid, $score, $max, $weight, $comments);
            $ins->execute();
        }
        $ins->close();
        $this->conn->commit();
        return $aiReviewId;
    }
}
