<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Closure;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;
use Throwable;

final class ArticleContentGenerationService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiUsageQuotaService $usageQuota,
    ) {}

    public function generate(AiModel $aiModel, string $prompt, ?Closure $beforeProvider = null): AgentResponse
    {
        [$agent, $providerName, $modelId, $providerUrl] = $this->resolveRuntime($aiModel, 'article_content');

        $reservation = $this->reserveDailyUsage($aiModel);
        if ($reservation === null) {
            throw new RuntimeException('AI 模型不可用或已达到今日调用上限');
        }

        try {
            $beforeProvider?->__invoke($aiModel);
            $response = $agent->prompt($prompt, [], $providerName, $modelId);
        } catch (Throwable $exception) {
            $this->releaseDailyUsage($reservation);

            throw new RuntimeException(
                'AI 生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl),
                0,
                $exception,
            );
        }

        if (OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? '')) === '') {
            $this->releaseDailyUsage($reservation);

            return $response;
        }

        $this->recordSuccessfulUsage($reservation);

        return $response;
    }

    public function stream(
        AiModel $aiModel,
        string $prompt,
        ?Closure $beforeSuccess = null,
    ): StreamableAgentResponse {
        $session = $this->deferredStream($aiModel, $prompt);
        $upstream = $session->stream;

        return new StreamableAgentResponse(
            $upstream->invocationId,
            function () use ($upstream, $session, $beforeSuccess): iterable {
                try {
                    foreach ($upstream as $event) {
                        yield $event;
                    }

                    if (OpenAiRuntimeProvider::normalizeGeneratedText((string) ($upstream->text ?? '')) === '') {
                        return;
                    }

                    $beforeSuccess?->__invoke();
                    $session->complete();
                } finally {
                    $session->abort();
                }
            },
            $session->meta,
        );
    }

    public function deferredStream(AiModel $aiModel, string $prompt): ArticleContentStreamSession
    {
        $runtime = $this->resolveRuntime($aiModel, 'article_editor');

        $reservation = $this->reserveDailyUsage($aiModel);
        if ($reservation === null) {
            throw new RuntimeException('AI 模型不可用或已达到今日调用上限');
        }

        return $this->deferredStreamWithRuntime($prompt, $runtime, $reservation);
    }

    public function deferredStreamWithReservation(
        AiModel $aiModel,
        string $prompt,
        AiUsageReservation $reservation,
        ?Closure $beforeProvider = null,
    ): ArticleContentStreamSession {
        try {
            $runtime = $this->resolveRuntime($aiModel, 'article_editor');
        } catch (Throwable $exception) {
            $this->releaseDailyUsage($reservation);

            throw $exception;
        }

        return $this->deferredStreamWithRuntime($prompt, $runtime, $reservation, $beforeProvider);
    }

    /**
     * @param  array{MarkdownContentWriterAgent,string,string,string}  $runtime
     */
    private function deferredStreamWithRuntime(
        string $prompt,
        array $runtime,
        AiUsageReservation $reservation,
        ?Closure $beforeProvider = null,
    ): ArticleContentStreamSession {
        [$agent, $providerName, $modelId, $providerUrl] = $runtime;

        try {
            $beforeProvider?->__invoke();
            $upstream = $agent->stream($prompt, [], $providerName, $modelId);
        } catch (Throwable $exception) {
            $this->releaseDailyUsage($reservation);

            throw new RuntimeException(
                'AI 生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl),
                0,
                $exception,
            );
        }

        $stream = new StreamableAgentResponse(
            $upstream->invocationId,
            function () use ($upstream, $reservation, $providerUrl): iterable {
                $streamEnded = false;

                try {
                    foreach ($upstream as $event) {
                        yield $event;
                    }
                    $streamEnded = true;
                } catch (Throwable $exception) {
                    if ($exception instanceof AiModelAccessException) {
                        throw $exception;
                    }

                    throw new RuntimeException(
                        'AI 生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl),
                        0,
                        $exception,
                    );
                } finally {
                    if (! $streamEnded) {
                        $this->releaseDailyUsage($reservation);
                    }
                }
            },
            new Meta($providerName, $modelId),
        );

        return new ArticleContentStreamSession(
            $stream,
            new Meta($providerName, $modelId),
            fn () => $this->recordSuccessfulUsage($reservation),
            fn () => $this->releaseDailyUsage($reservation),
        );
    }

    public function maxTokens(AiModel $aiModel): int
    {
        $configured = (int) ($aiModel->max_tokens ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(256, (int) config('geoflow.content_max_tokens', 16384));
    }

    public function providerTimeoutSeconds(): int
    {
        return MarkdownContentWriterAgent::PROVIDER_TIMEOUT_SECONDS;
    }

    /**
     * 原子预占一次调用额度，避免并发请求同时越过每日上限。
     */
    public function reserveDailyUsage(AiModel $aiModel): ?AiUsageReservation
    {
        return $this->usageQuota->reserveModel($aiModel);
    }

    /**
     * 供应商调用启动失败或流式响应异常时释放已预占额度。
     */
    public function releaseDailyUsage(AiUsageReservation $reservation): void
    {
        $this->usageQuota->releaseModel($reservation);
    }

    /**
     * AI 已完整响应后累计历史调用次数。统计失败不应中断正文交付。
     */
    private function recordSuccessfulUsage(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelSuccess($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array{MarkdownContentWriterAgent, string, string, string}
     */
    private function resolveRuntime(AiModel $aiModel, string $registrySlot): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API 地址为空');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }

        $modelId = trim((string) ($aiModel->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException('AI 模型标识为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $providerName = OpenAiRuntimeProvider::registerProvider($registrySlot, $driver, $providerUrl, $apiKey);

        return [
            new MarkdownContentWriterAgent(maxTokens: $this->maxTokens($aiModel)),
            $providerName,
            $modelId,
            $providerUrl,
        ];
    }
}
