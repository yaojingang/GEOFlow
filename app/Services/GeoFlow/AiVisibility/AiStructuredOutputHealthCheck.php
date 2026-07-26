<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;

final class AiStructuredOutputHealthCheck
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityHttpClientFactory $httpClientFactory,
    ) {}

    /**
     * @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,structured_output:array<string,mixed>,raw_preview:string}
     */
    public function testDeepSeekJsonOutput(AiModel $model, string $query): array
    {
        $modelId = $this->modelId($model, 'DeepSeek');
        $endpoint = $this->chatCompletionsEndpoint($model, 'DeepSeek');
        $payload = [
            'model' => $modelId,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Output valid json only. Use this schema: {"keyword":"string","intent":"string","source_actions":["string"],"confidence":0.0}.',
                ],
                [
                    'role' => 'user',
                    'content' => sprintf('Return json for GEO/AI visibility keyword "%s".', $this->testQuery($query)),
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'max_tokens' => 300,
        ];

        $response = $this->postJson($endpoint, $this->apiKey($model, 'DeepSeek'), $payload, 'DeepSeek');
        $content = $this->extractChatCompletionText($response['json']);
        $structuredOutput = $this->parseStructuredOutput($content, 'DeepSeek');

        return [
            'provider' => 'deepseek',
            'endpoint' => $endpoint,
            'http_status' => $response['http_status'],
            'latency_ms' => $response['latency_ms'],
            'structured_output' => $structuredOutput,
            'raw_preview' => $this->preview($content),
        ];
    }

    /**
     * @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,structured_output:array<string,mixed>,raw_preview:string}
     */
    public function testArkResponsesStructuredOutput(AiModel $model, string $query): array
    {
        $modelId = $this->modelId($model, '豆包 Ark');
        $endpoint = $this->arkResponsesEndpoint($model);
        $payload = [
            'model' => $modelId,
            'stream' => false,
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => sprintf('请用 JSON 分析 GEO/AI 可见性关键词“%s”。', $this->testQuery($query)),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'geoflow_api_health_check',
                    'schema' => $this->healthCheckSchema(),
                    'strict' => true,
                ],
            ],
            'temperature' => 0,
            'max_output_tokens' => 300,
        ];

        $response = $this->postJson($endpoint, $this->apiKey($model, '豆包 Ark'), $payload, '豆包 Ark');
        $content = $this->extractArkResponsesText($response['json']);
        $structuredOutput = $this->parseStructuredOutput($content, '豆包 Ark');

        return [
            'provider' => 'doubao_ark',
            'endpoint' => $endpoint,
            'http_status' => $response['http_status'],
            'latency_ms' => $response['latency_ms'],
            'structured_output' => $structuredOutput,
            'raw_preview' => $this->preview($content),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{json:array<string,mixed>,http_status:int,latency_ms:int}
     */
    private function postJson(string $endpoint, string $apiKey, array $payload, string $label): array
    {
        $startedAt = hrtime(true);
        $response = $this->httpClientFactory
            ->jsonRequest($apiKey)
            ->post($endpoint, $payload);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                '%s 结构化输出测试失败：HTTP %d %s',
                $label,
                $response->status(),
                $this->preview($response->body()),
            ));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException($label.' 结构化输出测试返回了非 JSON 响应');
        }

        return [
            'json' => $json,
            'http_status' => $response->status(),
            'latency_ms' => $latencyMs,
        ];
    }

    private function chatCompletionsEndpoint(AiModel $model, string $label): string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($baseUrl === '') {
            throw new RuntimeException($label.' API 地址为空');
        }

        return rtrim($baseUrl, '/').'/chat/completions';
    }

    private function arkResponsesEndpoint(AiModel $model): string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($baseUrl === '') {
            throw new RuntimeException('豆包 Ark API 地址为空');
        }

        $baseUrl = rtrim($baseUrl, '/');
        if (preg_match('#/responses$#', $baseUrl) === 1) {
            return $baseUrl;
        }

        $path = trim((string) config('geoflow.ai_visibility.ark_responses_path', '/responses'));
        $path = $path !== '' ? $path : '/responses';

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function modelId(AiModel $model, string $label): string
    {
        $modelId = trim((string) ($model->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException($label.' 模型 ID 为空');
        }

        return $modelId;
    }

    private function apiKey(AiModel $model, string $label): string
    {
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException($label.' API Key 为空');
        }

        return $apiKey;
    }

    /**
     * @return array<string,mixed>
     */
    private function healthCheckSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => ['type' => 'string'],
                'intent' => ['type' => 'string'],
                'source_actions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['keyword', 'intent', 'source_actions', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $json
     */
    private function extractChatCompletionText(array $json): string
    {
        $choice = is_array($json['choices'][0] ?? null) ? $json['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        return OpenAiRuntimeProvider::normalizeGeneratedText($this->stringifyContent($message['content'] ?? ($choice['text'] ?? '')));
    }

    /**
     * @param  array<string,mixed>  $json
     */
    private function extractArkResponsesText(array $json): string
    {
        $outputText = $this->stringifyContent($json['output_text'] ?? '');
        if ($outputText !== '') {
            return OpenAiRuntimeProvider::normalizeGeneratedText($outputText);
        }

        $segments = [];
        $output = is_array($json['output'] ?? null) ? $json['output'] : [];
        foreach ($output as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            foreach ($content as $part) {
                if (is_array($part)) {
                    $segments[] = $this->stringifyContent($part['text'] ?? '');
                }
            }
        }

        return OpenAiRuntimeProvider::normalizeGeneratedText(trim(implode('', $segments)));
    }

    /**
     * @return array<string,mixed>
     */
    private function parseStructuredOutput(string $content, string $label): array
    {
        $jsonText = $this->extractJsonObject($content);
        if ($jsonText === '') {
            throw new RuntimeException($label.' 未返回可解析的 JSON 对象');
        }

        $decoded = json_decode($jsonText, true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' 返回的结构化 JSON 无法解析：'.json_last_error_msg());
        }

        foreach (['keyword', 'intent', 'source_actions', 'confidence'] as $key) {
            if (! array_key_exists($key, $decoded)) {
                throw new RuntimeException($label.' 结构化 JSON 缺少字段：'.$key);
            }
        }

        if (! is_array($decoded['source_actions']) || ! is_numeric($decoded['confidence'])) {
            throw new RuntimeException($label.' 结构化 JSON 字段类型不符合预期');
        }

        return $decoded;
    }

    private function extractJsonObject(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?: $content;
        $content = trim($content);

        if ($content !== '' && str_starts_with($content, '{') && str_ends_with($content, '}')) {
            return $content;
        }

        $start = mb_strpos($content, '{', 0, 'UTF-8');
        $end = mb_strrpos($content, '}', 0, 'UTF-8');
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        return mb_substr($content, $start, $end - $start + 1, 'UTF-8');
    }

    private function stringifyContent(mixed $content): string
    {
        if (is_string($content) || is_numeric($content)) {
            return trim((string) $content);
        }

        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part) || is_numeric($part)) {
                $text .= (string) $part;

                continue;
            }

            if (is_array($part)) {
                $text .= $this->stringifyContent($part['text'] ?? $part['content'] ?? '');
            }
        }

        return trim($text);
    }

    private function testQuery(string $query): string
    {
        $query = trim($query);

        return $query !== '' ? mb_substr($query, 0, 120, 'UTF-8') : 'GEOFlow';
    }

    private function preview(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: $value);

        return mb_strlen($value, 'UTF-8') > 300 ? mb_substr($value, 0, 300, 'UTF-8').'...' : $value;
    }
}
