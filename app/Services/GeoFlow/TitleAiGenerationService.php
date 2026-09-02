<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\TitleGeneratorAgent;
use App\Models\AiModel;
use App\Models\Title;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Throwable;

/**
 * 标题 AI 生成服务。
 *
 * 该服务负责：
 * 1. 基于 ai_models 配置发起真实模型调用；
 * 2. 将模型结果和失败原因转换为稳定的领域结果；
 * 3. 记录成功调用及已发起的失败调用，执行每日额度保护。
 */
class TitleAiGenerationService
{
    /**
     * 复用统一 API Key 解密组件，避免标题生成链路与其他 AI 链路出现差异。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelFailoverDecider $failoverDecider,
    ) {}

    /**
     * 生成标题列表。
     *
     * @param  list<string>  $keywords
     */
    public function generateTitles(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt = ''
    ): TitleGenerationOutcome {
        $reservation = null;

        try {
            $reservation = $this->usageQuota->reserveModel($aiModel);
            if ($reservation === null) {
                $available = AiModel::query()
                    ->whereKey($aiModel->getKey())
                    ->where('status', 'active')
                    ->exists();

                return $available
                    ? TitleGenerationOutcome::quotaExhausted()
                    : TitleGenerationOutcome::failed('ai_model_unavailable');
            }

            $content = $this->requestTitlesFromModel($aiModel, $keywords, $count, $style, $customPrompt);
            $titles = $this->parseGeneratedTitles($content);
            if ($titles !== []) {
                try {
                    $this->usageQuota->recordModelSuccess($reservation);
                } catch (Throwable $exception) {
                    report($exception);
                }

                return TitleGenerationOutcome::success($titles);
            }
        } catch (Throwable $exception) {
            if ($reservation instanceof AiUsageReservation) {
                $this->recordAttempt($reservation);
            }

            return TitleGenerationOutcome::failed(
                $this->safeFailureCode($exception),
                retryable: $this->failoverDecider->shouldFailover($exception),
            );
        }

        if ($reservation instanceof AiUsageReservation) {
            $this->recordAttempt($reservation);
        }

        return TitleGenerationOutcome::failed('empty_result', retryable: true);
    }

    /**
     * 请求真实模型生成标题。
     *
     * @param  list<string>  $keywords
     */
    private function requestTitlesFromModel(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt
    ): string {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException('ai_url_missing');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ai_key_missing');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('title_ai', $driver, $providerUrl, $apiKey);

        $styleMap = [
            'professional' => '专业严谨的',
            'attractive' => '吸引眼球的',
            'seo' => 'SEO优化的',
            'creative' => '创意新颖的',
            'question' => '疑问式的',
        ];
        $styleDescription = $styleMap[$style] ?? '专业严谨的';
        $keywordsText = implode('、', $keywords);

        $systemPrompt = "你是一个专业的内容标题生成专家。请根据提供的关键词生成{$styleDescription}文章标题。";
        $userPrompt = "请基于以下关键词生成 {$count} 个{$styleDescription}文章标题：\n\n关键词：{$keywordsText}\n\n";
        if ($customPrompt !== '') {
            $userPrompt .= "额外要求：{$customPrompt}\n\n";
        }
        $userPrompt .= "要求：\n1. 每个标题独占一行\n2. 标题要有吸引力和可读性\n3. 适合搜索引擎优化\n4. 不要添加序号或其他标记\n5. 直接输出标题内容";

        $configuredMaxTokens = (int) ($aiModel->max_tokens ?? 0);
        $outputTokenLimit = max(512, min(4096, $count * 64));
        if ($configuredMaxTokens > 0) {
            $outputTokenLimit = min($outputTokenLimit, $configuredMaxTokens);
        }

        try {
            $response = (new TitleGeneratorAgent($systemPrompt, $outputTokenLimit))->prompt(
                prompt: $userPrompt,
                attachments: [],
                provider: $providerName,
                model: (string) ($aiModel->model_id ?? ''),
                timeout: (int) config('geoflow.title_ai_request_timeout_seconds', 90),
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException('ai_provider_request_failed', 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);

        $maxCharacters = max(2000, $count * 600);
        $maxLines = max(10, $count * 3);
        if (mb_strlen($content, 'UTF-8') > $maxCharacters
            || count(preg_split('/\R/u', $content) ?: []) > $maxLines) {
            throw new \RuntimeException('ai_title_response_too_large');
        }

        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new \RuntimeException('ai_empty_stream_content');
            }

            throw new \RuntimeException('ai_empty_content');
        }

        return $content;
    }

    /**
     * 解析模型输出文本为标题列表。
     *
     * @return list<string>
     */
    private function parseGeneratedTitles(string $content): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/u', $content) ?: [],
            static fn (string $line): bool => preg_match('/^\s*```/u', $line) !== 1,
        ));
        $candidate = trim(implode("\n", $lines));
        $decoded = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                return [];
            }
            $lines = array_map(
                static fn (mixed $title): string => is_string($title) || is_numeric($title)
                    ? (string) $title
                    : '',
                $decoded,
            );
        }

        $titles = [];
        foreach ($lines as $line) {
            $title = self::normalizeTitle($line);
            if ($title === '') {
                continue;
            }
            $titles[] = $title;
        }

        return array_values(array_unique($titles));
    }

    public static function normalizeTitle(string $title): string
    {
        $normalized = Title::normalizeText($title);
        if (preg_match('/^(?:```|#{1,6}\s|(?:以下|下面).{0,20}(?:标题|列表)|(?:here\s+are|titles?\s*:)|(?:aqui\s+est[aã]o|t[ií]tulos?\s*:))/iu', $normalized) === 1) {
            return '';
        }

        $normalized = preg_replace(
            '/^(?:\d+(?:(?:\.(?!\d)|[)）、])\s*|\s*[-:：]\s+(?!\d))|\s+|[-*•]\s*)/u',
            '',
            $normalized,
        );

        return Title::normalizeText((string) $normalized);
    }

    /**
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    private function safeFailureCode(Throwable $exception): string
    {
        $code = trim($exception->getMessage());

        return in_array($code, [
            'ai_url_missing',
            'ai_key_missing',
            'ai_model_unavailable',
            'ai_provider_request_failed',
            'ai_title_response_too_large',
            'ai_empty_stream_content',
            'ai_empty_content',
        ], true) ? $code : 'ai_provider_request_failed';
    }

    private function recordAttempt(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelAttempt($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
