<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;

final class DoubaoArkResponsesClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityHttpClientFactory $httpClientFactory,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function answerWithWebSearch(
        AiModel $model,
        string $prompt,
        array $options = [],
        ?AiVisibilityPreparedModelRequest $preparedRequest = null,
    ): AiVisibilityResult {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('豆包 Ark Responses 查询提示词为空');
        }

        $modelId = trim((string) ($model->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException('豆包 Ark 模型 ID 为空');
        }

        $endpoint = $this->responsesEndpoint($model);
        $apiKey = $this->apiKey($model);

        $preparedRequest ??= $this->prepareRequest($model, $prompt, $options);
        $payload = $preparedRequest->providerPayload;
        if (! is_array($payload)) {
            throw new RuntimeException('豆包 Ark Responses 请求结构无效');
        }
        $startedAt = hrtime(true);
        $response = $this->httpClientFactory
            ->singleAttemptJsonRequest($apiKey)
            ->post($endpoint, $payload);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                '豆包 Ark Responses 请求失败：HTTP %d %s',
                $response->status(),
                trim($response->body())
            ));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('豆包 Ark Responses 返回了非 JSON 结构');
        }

        return $this->normalizer->normalizeArkResponses($json, [
            'endpoint' => $endpoint,
            'payload' => $payload,
        ], $modelId, $latencyMs);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function requestPayload(AiModel $model, string $prompt, array $options = []): array
    {
        $prompt = trim($prompt);
        $modelId = trim((string) ($model->model_id ?? ''));
        if ($prompt === '') {
            throw new RuntimeException('豆包 Ark Responses 查询提示词为空');
        }
        if ($modelId === '') {
            throw new RuntimeException('豆包 Ark 模型 ID 为空');
        }

        return $this->buildPayload($modelId, $prompt, $options);
    }

    /** @param array<string,mixed> $options */
    public function prepareRequest(AiModel $model, string $prompt, array $options = []): AiVisibilityPreparedModelRequest
    {
        $payload = $this->requestPayload($model, $prompt, $options);

        return new AiVisibilityPreparedModelRequest(
            providerPayload: $payload,
            digestPayload: json_encode(
                $this->canonicalizeArray($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildPayload(string $modelId, string $prompt, array $options): array
    {
        $maxKeyword = max(1, min(10, (int) ($options['max_keyword'] ?? 2)));

        $payload = [
            'model' => $modelId,
            'stream' => false,
            'tools' => [
                [
                    'type' => 'web_search',
                    'max_keyword' => $maxKeyword,
                ],
            ],
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        foreach (['temperature', 'top_p', 'max_output_tokens'] as $key) {
            if (array_key_exists($key, $options)) {
                $payload[$key] = $options[$key];
            }
        }

        return $payload;
    }

    /** @param array<string|int,mixed> $value */
    private function canonicalizeArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeArray($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function responsesEndpoint(AiModel $model): string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($baseUrl === '') {
            throw new RuntimeException('豆包 Ark API 地址为空');
        }

        $path = trim((string) config('geoflow.ai_visibility.ark_responses_path', '/responses'));
        $path = $path !== '' ? $path : '/responses';

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function apiKey(AiModel $model): string
    {
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('豆包 Ark API Key 为空');
        }

        return $apiKey;
    }
}
