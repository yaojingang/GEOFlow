<?php
/**
 * Embedding 服务封装
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function embedding_service_storage_dimensions(): int {
    return 3072;
}

function embedding_service_vector_literal(array $vector): string {
    $values = [];
    foreach ($vector as $value) {
        $values[] = (float) $value;
    }

    return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

function embedding_service_pad_vector(array $vector, int $storageDimensions = 3072): array {
    $storageDimensions = max(1, $storageDimensions);
    $normalized = [];
    foreach ($vector as $value) {
        $normalized[] = (float) $value;
    }

    if (count($normalized) > $storageDimensions) {
        $normalized = array_slice($normalized, 0, $storageDimensions);
    }

    while (count($normalized) < $storageDimensions) {
        $normalized[] = 0.0;
    }

    return $normalized;
}

function embedding_service_pgvector_available(PDO $db): bool {
    static $cache = [];

    $cacheKey = spl_object_id($db);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $db->query("
            SELECT EXISTS (
                SELECT 1
                FROM pg_type
                WHERE typname = 'vector'
            )
        ");
        $cache[$cacheKey] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function embedding_service_get_default_model(PDO $db): ?array {
    static $cache = [];

    $cacheKey = spl_object_id($db);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $preferredId = (int) get_setting('default_embedding_model_id', 0);
    if ($preferredId > 0) {
        $stmt = $db->prepare("
            SELECT *
            FROM ai_models
            WHERE id = ?
              AND status = 'active'
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'
            LIMIT 1
        ");
        $stmt->execute([$preferredId]);
        $model = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($model) {
            $model['api_key'] = decrypt_ai_api_key((string) ($model['api_key'] ?? ''));
            return $cache[$cacheKey] = $model;
        }
    }

    $stmt = $db->query("
        SELECT *
        FROM ai_models
        WHERE status = 'active'
          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $model = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

    if ($model) {
        $model['api_key'] = decrypt_ai_api_key((string) ($model['api_key'] ?? ''));
    }

    return $cache[$cacheKey] = $model;
}

function embedding_service_call_api(array $model, array $inputs): array {
    $apiUrl = trim((string) ($model['api_url'] ?? ''));
    $modelId = trim((string) ($model['model_id'] ?? ''));
    $apiKey = trim((string) ($model['api_key'] ?? ''));

    if ($apiUrl === '' || $modelId === '' || $apiKey === '') {
        throw new RuntimeException('Embedding 模型配置不完整');
    }

    $payload = [
        'model' => $modelId,
        'input' => array_values($inputs),
    ];

    $ch = curl_init();
    apply_curl_network_defaults($ch);
    curl_setopt_array($ch, [
        CURLOPT_URL => ai_embedding_endpoint_from_url($apiUrl),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('Embedding API CURL错误: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException('Embedding API调用失败，HTTP状态码: ' . $httpCode . ', 响应: ' . (string) $response);
    }

    $result = json_decode((string) $response, true);
    if (!is_array($result) || !isset($result['data']) || !is_array($result['data'])) {
        throw new RuntimeException('Embedding API响应格式错误: ' . (string) $response);
    }

    $vectors = [];
    foreach ($result['data'] as $item) {
        if (!isset($item['embedding']) || !is_array($item['embedding'])) {
            throw new RuntimeException('Embedding API返回缺少 embedding 字段');
        }
        $vectors[] = $item['embedding'];
    }

    if (count($vectors) !== count($inputs)) {
        throw new RuntimeException('Embedding API返回数量与输入不一致');
    }

    return $vectors;
}

function embedding_service_update_model_usage(PDO $db, int $modelId): void {
    if ($modelId <= 0) {
        return;
    }

    $stmt = $db->prepare("SELECT DATE(updated_at) as last_update FROM ai_models WHERE id = ?");
    $stmt->execute([$modelId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');

    if ($result && ($result['last_update'] ?? '') !== $today) {
        $stmt = $db->prepare("UPDATE ai_models SET used_today = 1, total_used = total_used + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    } else {
        $stmt = $db->prepare("UPDATE ai_models SET used_today = used_today + 1, total_used = total_used + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    }

    $stmt->execute([$modelId]);
}

function embedding_service_generate_embeddings(PDO $db, array $inputs, ?array $model = null): array {
    $cleanInputs = [];
    foreach ($inputs as $input) {
        $input = trim((string) $input);
        if ($input !== '') {
            $cleanInputs[] = $input;
        }
    }

    if (empty($cleanInputs)) {
        return [];
    }

    $model = $model ?: embedding_service_get_default_model($db);
    if (!$model) {
        throw new RuntimeException('未配置可用的 embedding 模型');
    }

    $vectors = embedding_service_call_api($model, $cleanInputs);
    embedding_service_update_model_usage($db, (int) ($model['id'] ?? 0));

    $storageDimensions = embedding_service_storage_dimensions();
    $results = [];
    foreach ($vectors as $index => $vector) {
        $actualDimensions = is_array($vector) ? count($vector) : 0;
        $padded = embedding_service_pad_vector(is_array($vector) ? $vector : [], $storageDimensions);
        $results[] = [
            'input' => $cleanInputs[$index],
            'raw_vector' => is_array($vector) ? $vector : [],
            'vector' => $padded,
            'vector_literal' => embedding_service_vector_literal($padded),
            'dimensions' => $actualDimensions,
            'model_id' => (int) ($model['id'] ?? 0),
            'model_name' => (string) ($model['name'] ?? ''),
            'model_identifier' => (string) ($model['model_id'] ?? ''),
            'provider' => parse_url((string) ($model['api_url'] ?? ''), PHP_URL_HOST) ?: '',
        ];
    }

    return $results;
}
