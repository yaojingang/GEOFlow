<?php

declare(strict_types=1);

$configFile = __DIR__.'/config.php';
$config = is_file($configFile) ? require $configFile : [];
if (! is_array($config)) {
    $config = [];
}

$keyId = (string) (getenv('GEOFLOW_KEY_ID') ?: ($config['key_id'] ?? ''));
$secret = (string) (getenv('GEOFLOW_SECRET') ?: ($config['secret'] ?? ''));
$storageDir = (string) (getenv('GEOFLOW_STORAGE_DIR') ?: ($config['storage_dir'] ?? (__DIR__.'/storage')));
$publicBaseUrl = rtrim((string) (getenv('GEOFLOW_PUBLIC_BASE_URL') ?: ($config['public_base_url'] ?? '')), '/');
$clockSkewSeconds = max(30, (int) (getenv('GEOFLOW_CLOCK_SKEW_SECONDS') ?: ($config['clock_skew_seconds'] ?? 300)));

function geoflow_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function geoflow_header(string $name): string
{
    $serverName = 'HTTP_'.strtoupper(str_replace('-', '_', $name));

    return is_string($_SERVER[$serverName] ?? null) ? (string) $_SERVER[$serverName] : '';
}

function geoflow_safe_filename(string $value): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value);

    return trim(is_string($safe) ? $safe : '', '-_.') ?: hash('sha256', $value);
}

function geoflow_verify_request(string $expectedKeyId, string $secret, string $method, string $path, string $body, int $clockSkewSeconds): array
{
    if ($expectedKeyId === '' || $secret === '') {
        geoflow_json(500, [
            'ok' => false,
            'error' => 'agent_not_configured',
        ]);
    }

    $keyId = geoflow_header('X-GEOFlow-Key-Id');
    $timestamp = geoflow_header('X-GEOFlow-Timestamp');
    $nonce = geoflow_header('X-GEOFlow-Nonce');
    $bodyHash = geoflow_header('X-GEOFlow-Body-SHA256');
    $signature = geoflow_header('X-GEOFlow-Signature');
    $event = geoflow_header('X-GEOFlow-Event');
    $idempotencyKey = geoflow_header('X-GEOFlow-Idempotency-Key');

    if ($keyId === '' || $timestamp === '' || $nonce === '' || $bodyHash === '' || $signature === '' || $event === '' || $idempotencyKey === '') {
        geoflow_json(401, [
            'ok' => false,
            'error' => 'missing_signature_headers',
        ]);
    }

    if (! hash_equals($expectedKeyId, $keyId)) {
        geoflow_json(403, [
            'ok' => false,
            'error' => 'key_id_not_allowed',
        ]);
    }

    try {
        $requestTime = new DateTimeImmutable($timestamp);
    } catch (Throwable) {
        geoflow_json(401, [
            'ok' => false,
            'error' => 'invalid_timestamp',
        ]);
    }

    if (abs(time() - $requestTime->getTimestamp()) > $clockSkewSeconds) {
        geoflow_json(401, [
            'ok' => false,
            'error' => 'timestamp_out_of_range',
        ]);
    }

    $bodyForSignature = $method === 'GET' && $body === '' ? '{}' : $body;
    $expectedBodyHash = hash('sha256', $bodyForSignature);
    if (! hash_equals($expectedBodyHash, $bodyHash)) {
        geoflow_json(401, [
            'ok' => false,
            'error' => 'body_hash_mismatch',
        ]);
    }

    $signingString = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;
    $expectedSignature = hash_hmac('sha256', $signingString, $secret);
    if (! hash_equals($expectedSignature, $signature)) {
        geoflow_json(401, [
            'ok' => false,
            'error' => 'signature_invalid',
        ]);
    }

    return [
        'event' => $event,
        'idempotency_key' => $idempotencyKey,
    ];
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';
$body = file_get_contents('php://input');
$body = is_string($body) ? $body : '';
$verified = geoflow_verify_request($keyId, $secret, $method, $path, $body, $clockSkewSeconds);

if ($method === 'GET' && $path === '/geoflow-agent/v1/health') {
    geoflow_json(200, [
        'ok' => true,
        'service' => 'geoflow-agent',
        'event' => $verified['event'],
        'time' => gmdate('c'),
    ]);
}

if ($method !== 'POST' || $path !== '/geoflow-agent/v1/articles') {
    geoflow_json(404, [
        'ok' => false,
        'error' => 'route_not_found',
    ]);
}

if ($verified['event'] !== 'article.publish') {
    geoflow_json(422, [
        'ok' => false,
        'error' => 'unsupported_event',
    ]);
}

$payload = json_decode($body, true);
if (! is_array($payload) || ! is_array($payload['article'] ?? null)) {
    geoflow_json(422, [
        'ok' => false,
        'error' => 'invalid_article_payload',
    ]);
}

$article = $payload['article'];
$idempotencyKey = (string) $verified['idempotency_key'];
$articleId = is_scalar($article['id'] ?? null) ? (string) $article['id'] : hash('sha256', $idempotencyKey);
$slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : 'article-'.$articleId;
$remoteId = 'geoflow-'.$articleId;
$remoteUrl = $publicBaseUrl !== '' ? $publicBaseUrl.'/article/'.rawurlencode($slug) : null;

$articleDir = rtrim($storageDir, '/').'/articles';
if (! is_dir($articleDir) && ! mkdir($articleDir, 0755, true) && ! is_dir($articleDir)) {
    geoflow_json(500, [
        'ok' => false,
        'error' => 'storage_not_writable',
    ]);
}

$recordFile = $articleDir.'/'.geoflow_safe_filename($idempotencyKey).'.json';
if (is_file($recordFile)) {
    $stored = json_decode((string) file_get_contents($recordFile), true);
    if (is_array($stored['response'] ?? null)) {
        geoflow_json(200, $stored['response']);
    }
}

$response = [
    'ok' => true,
    'remote_id' => $remoteId,
    'remote_url' => $remoteUrl,
    'status' => 'stored',
];

file_put_contents($recordFile, json_encode([
    'received_at' => gmdate('c'),
    'idempotency_key' => $idempotencyKey,
    'headers' => [
        'event' => $verified['event'],
    ],
    'payload' => $payload,
    'response' => $response,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

geoflow_json(200, $response);
