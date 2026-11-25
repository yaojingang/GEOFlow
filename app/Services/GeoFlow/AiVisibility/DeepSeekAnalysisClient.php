<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RuntimeException;
use Throwable;

final class DeepSeekAnalysisClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @param  array<string,mixed>  $options
     */
    public function analyze(
        AiModel $model,
        string $prompt,
        array $sources = [],
        array $options = [],
        ?AiVisibilityPreparedModelRequest $preparedRequest = null,
    ): AiVisibilityResult {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('DeepSeek 分析提示词为空');
        }

        $modelId = trim((string) ($model->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException('DeepSeek 模型 ID 为空');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('DeepSeek API 地址为空');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('DeepSeek API Key 为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $providerName = OpenAiRuntimeProvider::registerProvider('ai_visibility_deepseek', $driver, $providerUrl, $apiKey);
        $maxTokens = (int) ($options['max_tokens'] ?? config('geoflow.ai_visibility.default_analysis_max_tokens', 4096));
        $agent = new MarkdownContentWriterAgent(
            instructions: '你是 GEO/AI 可见性分析助手。请基于输入的 AI 回答和信源做可执行分析，明确区分事实、推断和投放建议。',
            maxTokens: $maxTokens > 0 ? $maxTokens : null,
        );

        $preparedRequest ??= $this->prepareRequest($prompt, $sources);
        $fullPrompt = $preparedRequest->providerPayload;
        if (! is_string($fullPrompt)) {
            throw new RuntimeException('DeepSeek 分析请求结构无效');
        }
        $request = [
            'provider_url' => $providerUrl,
            'model_id' => $modelId,
            'prompt' => $fullPrompt,
            'source_count' => count($sources),
        ];

        $startedAt = hrtime(true);
        // 2026-11-11：改为非流式直连 OpenAI 兼容接口，规避 SDK 的 SSE 流式与自建代理不兼容（502/499）
        $timeoutSeconds = isset($options['timeout'])
            ? max(30, (int) $options['timeout'])
            : max(60, (int) config('geoflow.ai_visibility.analysis_http_timeout_seconds', 300));
        try {
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeoutSeconds)
                ->post(rtrim($providerUrl, '/').'/chat/completions', [
                    'model' => $modelId,
                    'messages' => [
                        ['role' => 'system', 'content' => '你是 GEO/AI 可见性分析助手。请基于输入的 AI 回答和信源做可执行分析，明确区分事实、推断和投放建议。'],
                        ['role' => 'user', 'content' => $fullPrompt],
                    ],
                    'max_tokens' => $maxTokens > 0 ? $maxTokens : 4096,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('DeepSeek 分析失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'DeepSeek 分析失败: HTTP %d %s',
                $response->status(),
                trim(substr((string) $response->body(), 0, 300))
            ));
        }

        $data = $response->json();
        $rawText = (string) ($data['choices'][0]['message']['content'] ?? '');
        $answerText = OpenAiRuntimeProvider::normalizeGeneratedText($rawText);
        if ($answerText === '') {
            throw new RuntimeException('DeepSeek 分析返回空内容');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return $this->normalizer->normalizeTextAnalysis(
            providerType: AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
            providerKey: 'deepseek',
            modelId: $modelId,
            answerText: $answerText,
            sources: $sources,
            usage: $usage,
            request: $request,
            rawResponse: [
                'text' => $rawText,
                'usage' => $usage,
            ],
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     */
    public function fullPrompt(string $prompt, array $sources): string
    {
        if ($sources === []) {
            return $prompt;
        }

        $sourceLines = [];
        foreach ($sources as $source) {
            $title = $source->title !== null ? $source->title : 'Untitled source';
            $url = $source->url !== null ? ' - '.$source->url : '';
            $summary = $source->summary ?? $source->snippet ?? '';
            $summary = $summary !== '' ? "\n摘要：".mb_substr($summary, 0, 500, 'UTF-8') : '';
            $sourceLines[] = sprintf('[%s] %s%s%s', $source->citationKey ?? 'S?', $title, $url, $summary);
        }

        return $prompt."\n\n可用信源如下（这些是外部搜索/工具返回的信源，不代表 DeepSeek 原生引用）：\n".implode("\n\n", $sourceLines);
    }

    /** @param list<AiVisibilitySourceData> $sources */
    public function prepareRequest(string $prompt, array $sources): AiVisibilityPreparedModelRequest
    {
        $fullPrompt = $this->fullPrompt($prompt, $sources);

        return new AiVisibilityPreparedModelRequest($fullPrompt, $fullPrompt);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractUsage(object $response): array
    {
        $usage = $response->usage ?? null;
        if ($usage instanceof Arrayable) {
            return $usage->toArray();
        }
        if ($usage instanceof JsonSerializable) {
            $serialized = $usage->jsonSerialize();

            return is_array($serialized) ? $serialized : [];
        }

        return is_array($usage) ? $usage : [];
    }
}
