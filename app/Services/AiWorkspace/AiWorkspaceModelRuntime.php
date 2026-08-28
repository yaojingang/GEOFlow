<?php

namespace App\Services\AiWorkspace;

use App\Ai\Agents\AdminHelpAssistant;
use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Generator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Throwable;

final readonly class AiWorkspaceModelRuntime implements AdminHelpResponder
{
    public function __construct(
        private ApiKeyCrypto $apiKeyCrypto,
        private AiUsageQuotaService $usageQuota,
        private AiWorkspaceModelReadiness $readiness,
    ) {}

    /**
     * @param  iterable<int, mixed>  $messages
     * @return Generator<int, array<string, mixed>, mixed, array{answer:string,meta:array<string,mixed>,usage:array<string,int>}>
     */
    public function stream(
        string $prompt,
        string $knowledgeContext,
        iterable $messages = [],
        ?int $adminId = null,
    ): Generator {
        $cache = $this->acquireConcurrencySlot();
        $lastException = null;
        $attempts = 0;
        $fallbackCount = 0;
        $degradedCount = 0;
        $deadline = microtime(true) + (int) config('ai-workspace.model_total_timeout_seconds', 90);
        $startedAtNanoseconds = hrtime(true);
        $modelStartedAt = now()->toISOString();
        $firstProviderEventMilliseconds = null;
        $firstTextMilliseconds = null;

        try {
            foreach ($this->models() as $model) {
                $attempts++;
                $reservation = null;
                $emitted = false;
                $answer = '';
                $streamEnded = false;
                $usage = [];
                $finishReason = null;
                $driver = '';
                $providerName = '';
                $plainTextFallback = false;

                try {
                    $timeout = $this->remainingAttemptTimeout($deadline);
                    [$provider, $reservation, $driver] = $this->modelContext($model, $adminId);
                    $agent = new AdminHelpAssistant(
                        $messages,
                        $knowledgeContext,
                        (string) $model->model_id,
                        $this->answerMaxTokens($model),
                    );

                    $plainTextFallback = $this->readiness->prefersPlainTextFallback($model);
                    if ($plainTextFallback) {
                        $fallbackCount++;
                        $degradedCount++;
                        $response = $agent->prompt($prompt, [], $provider, (string) $model->model_id, $timeout);
                        $firstProviderEventMilliseconds ??= $this->elapsedMilliseconds($startedAtNanoseconds);
                        $providerName = $driver;
                        $answer = trim((string) $response->text);
                        if ($answer === '') {
                            throw new RuntimeException('AI 模型未返回文本内容。');
                        }
                        $firstTextMilliseconds ??= $this->elapsedMilliseconds($startedAtNanoseconds);
                        $emitted = true;
                        $usage = $response->usage->toArray();
                        $finishReason = $response->steps->last()?->finishReason->value ?? 'stop';
                        yield [
                            'type' => 'status',
                            'stage' => 'connected',
                            'provider' => $providerName,
                            'model' => (string) $model->model_id,
                        ];
                        yield ['type' => 'delta', 'content' => $answer];
                    } else {
                        $stream = $agent->stream($prompt, [], $provider, (string) $model->model_id, $timeout);
                        foreach ($stream as $event) {
                            $firstProviderEventMilliseconds ??= $this->elapsedMilliseconds($startedAtNanoseconds);

                            if ($event instanceof StreamStart) {
                                $providerName = $driver;
                                yield [
                                    'type' => 'status',
                                    'stage' => 'connected',
                                    'provider' => $driver,
                                    'model' => $event->model,
                                ];

                                continue;
                            }
                            if ($event instanceof ReasoningStart) {
                                yield ['type' => 'status', 'stage' => 'reasoning'];

                                continue;
                            }
                            if ($event instanceof TextDelta && $event->delta !== '') {
                                $emitted = true;
                                $firstTextMilliseconds ??= $this->elapsedMilliseconds($startedAtNanoseconds);
                                $answer .= $event->delta;
                                yield ['type' => 'delta', 'content' => $event->delta];

                                continue;
                            }
                            if ($event instanceof StreamEnd) {
                                $streamEnded = true;
                                $finishReason = $event->reason;
                                $usage = $event->usage->toArray();

                                continue;
                            }
                            if ($event instanceof Error) {
                                throw new RuntimeException($event->message);
                            }
                        }
                        $answer = trim((string) $stream->text) ?: trim($answer);
                        if (! $this->streamCompletedSuccessfully($streamEnded, $finishReason)) {
                            throw new RuntimeException('AI 模型流式响应未正常完成。');
                        }
                    }
                    if ($answer === '') {
                        throw new RuntimeException('AI 模型未返回文本内容。');
                    }
                    $this->usageQuota->recordModelSuccess($reservation);
                    $this->recordProviderSuccess($model);
                    $totalMilliseconds = $this->elapsedMilliseconds($startedAtNanoseconds);
                    $performance = [
                        'provider_first_event_ms' => $firstProviderEventMilliseconds,
                        'ttft_ms' => $firstTextMilliseconds,
                        'total_ms' => $totalMilliseconds,
                    ];
                    $this->recordReadinessSuccess($model, ! $plainTextFallback, $performance);

                    return [
                        'answer' => $answer,
                        'meta' => [
                            'model_started_at' => $modelStartedAt,
                            ...$performance,
                            'attempts' => $attempts,
                            'fallback_count' => $fallbackCount,
                            'degraded_count' => $degradedCount,
                            'provider' => $providerName !== '' ? $providerName : $driver,
                            'model' => (string) $model->model_id,
                            'finish_reason' => $finishReason,
                        ],
                        'usage' => $usage,
                    ];
                } catch (Throwable $exception) {
                    if ($this->streamCompletedSuccessfully($streamEnded, $finishReason) && trim($answer) !== '') {
                        report($exception);
                        $this->usageQuota->recordModelSuccess($reservation);
                        $this->recordProviderSuccess($model);
                        $totalMilliseconds = $this->elapsedMilliseconds($startedAtNanoseconds);
                        $performance = [
                            'provider_first_event_ms' => $firstProviderEventMilliseconds,
                            'ttft_ms' => $firstTextMilliseconds,
                            'total_ms' => $totalMilliseconds,
                        ];
                        $this->recordReadinessSuccess($model, true, $performance);

                        return [
                            'answer' => trim($answer),
                            'meta' => [
                                'model_started_at' => $modelStartedAt,
                                ...$performance,
                                'attempts' => $attempts,
                                'fallback_count' => $fallbackCount,
                                'degraded_count' => $degradedCount,
                                'provider' => $providerName !== '' ? $providerName : $driver,
                                'model' => (string) $model->model_id,
                                'finish_reason' => $finishReason,
                                'late_stream_close' => true,
                            ],
                            'usage' => $usage,
                        ];
                    }
                    $lastException = $this->runtimeException($exception, $model);
                    $recoverable = $this->isRecoverableProviderFailure($exception);
                    if ($emitted) {
                        $this->recordProviderFailure($model);
                        throw $lastException;
                    }
                    if (! $recoverable) {
                        throw $lastException;
                    }
                    $fallbackCount++;
                    if (! $exception instanceof AiWorkspaceModelUnavailableException) {
                        $this->recordProviderFailure($model);
                        $this->boundedBackoff($attempts, $deadline);
                    }
                } finally {
                    if ($reservation !== null) {
                        $this->usageQuota->recordModelAttempt($reservation);
                    }
                }
            }

            throw $lastException ?? new RuntimeException('没有可用的对话模型');
        } finally {
            $this->releaseConcurrencySlot($cache);
        }
    }

    /** @param iterable<int, mixed> $messages */
    public function answer(
        string $prompt,
        string $knowledgeContext,
        iterable $messages = [],
        ?int $adminId = null,
    ): string {
        return $this->withConcurrencySlot(function () use ($prompt, $knowledgeContext, $messages, $adminId): string {
            $lastException = null;
            $attempt = 0;
            $deadline = microtime(true) + (int) config('ai-workspace.model_total_timeout_seconds', 90);

            foreach ($this->models() as $model) {
                $attempt++;
                $reservation = null;
                try {
                    $timeout = $this->remainingAttemptTimeout($deadline);
                    [$provider, $reservation] = $this->modelContext($model, $adminId);
                    $agent = new AdminHelpAssistant(
                        $messages,
                        $knowledgeContext,
                        (string) $model->model_id,
                        $this->answerMaxTokens($model),
                    );
                    $response = $agent->prompt($prompt, [], $provider, (string) $model->model_id, $timeout);
                    $answer = trim((string) $response->text);
                    if ($answer === '') {
                        throw new RuntimeException('AI 模型未返回文本内容。');
                    }
                    $this->usageQuota->recordModelSuccess($reservation);
                    $this->recordProviderSuccess($model);
                    $this->recordReadinessSuccess($model, false);

                    return $answer;
                } catch (Throwable $exception) {
                    if ($reservation !== null) {
                        $this->usageQuota->recordModelAttempt($reservation);
                    }
                    $lastException = $this->runtimeException($exception, $model);
                    if (! $this->isRecoverableProviderFailure($exception)) {
                        throw $lastException;
                    }
                    if (! $exception instanceof AiWorkspaceModelUnavailableException) {
                        $this->recordProviderFailure($model);
                        $this->boundedBackoff($attempt, $deadline);
                    }
                }
            }

            throw $lastException ?? new RuntimeException('没有可用的对话模型');
        });
    }

    /** @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,raw_preview:string} */
    public function probePlainText(AiModel $model, string $prompt, ?int $timeout = null): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
        $modelId = trim((string) $model->model_id);
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new AiWorkspaceModelUnavailableException('对话模型配置不完整');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $provider = OpenAiRuntimeProvider::registerProvider(
            'ai_workspace_probe_'.(int) $model->id,
            $driver,
            $providerUrl,
            $apiKey,
        );
        $startedAt = hrtime(true);
        $timeout = $this->probeTimeout($timeout);
        $response = $this->withConcurrencySlot(
            fn (): object => (new AdminHelpAssistant([], '模型连接检测。', $modelId))->prompt($prompt, [], $provider, $modelId, $timeout),
        );
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $text = trim((string) $response->text);
        if ($text === '') {
            throw new RuntimeException('AI 工作台普通文本检测没有返回内容。');
        }

        return [
            'provider' => $driver,
            'endpoint' => $providerUrl,
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'raw_preview' => Str::limit($text, 500, ''),
        ];
    }

    /** @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,raw_preview:string,delta_count:int} */
    public function probeStreaming(AiModel $model, string $prompt, ?int $timeout = null): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
        $modelId = trim((string) $model->model_id);
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new AiWorkspaceModelUnavailableException('对话模型配置不完整');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $provider = OpenAiRuntimeProvider::registerProvider(
            'ai_workspace_stream_probe_'.(int) $model->id,
            $driver,
            $providerUrl,
            $apiKey,
        );
        $startedAt = hrtime(true);
        $timeout = $this->probeTimeout($timeout);
        $result = $this->withConcurrencySlot(function () use ($modelId, $prompt, $provider, $timeout): array {
            $stream = (new AdminHelpAssistant([], '模型流式连接检测。', $modelId))->stream($prompt, [], $provider, $modelId, $timeout);
            $text = '';
            $deltaCount = 0;
            $streamEnded = false;
            $finishReason = null;

            foreach ($stream as $event) {
                if ($event instanceof TextDelta && $event->delta !== '') {
                    $text .= $event->delta;
                    $deltaCount++;

                    continue;
                }
                if ($event instanceof StreamEnd) {
                    $streamEnded = true;
                    $finishReason = $event->reason;

                    continue;
                }
                if ($event instanceof Error) {
                    throw new RuntimeException('AI 工作台流式检测收到错误事件。');
                }
            }

            $text = trim((string) $stream->text) ?: trim($text);
            if ($text === '' || $deltaCount === 0 || ! $this->streamCompletedSuccessfully($streamEnded, $finishReason)) {
                throw new RuntimeException('AI 工作台流式检测没有返回正文分片。');
            }

            return ['text' => $text, 'delta_count' => $deltaCount];
        });
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return [
            'provider' => $driver,
            'endpoint' => $providerUrl,
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'raw_preview' => Str::limit((string) $result['text'], 500, ''),
            'delta_count' => (int) $result['delta_count'],
        ];
    }

    /** @return iterable<int, AiModel> */
    private function models(): iterable
    {
        $models = AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')->orWhereNotIn('model_type', ['embedding', 'image']);
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiModel $model): bool => $this->readiness->canAttempt($model));
        $available = $models
            ->reject(fn (AiModel $model): bool => Cache::has($this->providerCircuitKey($model)))
            ->values();

        return $available->isNotEmpty() ? $available : $models->take(1)->values();
    }

    /** @return array{string, mixed, string} */
    private function modelContext(AiModel $model, ?int $adminId = null): array
    {
        $adminBudgetKey = null;
        $adminBudgetTtl = null;
        $adminBudgetReserved = false;
        if ($adminId !== null) {
            $adminBudgetKey = 'ai-workspace:model-budget:'.$adminId.':'.now()->toDateString();
            $adminBudgetTtl = max(60, now()->diffInSeconds(now()->endOfDay()));
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
        $modelId = trim((string) $model->model_id);
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new AiWorkspaceModelUnavailableException('对话模型配置不完整');
        }

        if ($adminBudgetKey !== null && $adminBudgetTtl !== null) {
            $attempts = RateLimiter::increment($adminBudgetKey, $adminBudgetTtl);
            if ($attempts > (int) config('ai-workspace.admin_daily_model_calls', 200)) {
                RateLimiter::decrement($adminBudgetKey, $adminBudgetTtl);
                throw new AiWorkspaceRuntimeGuardException('当前管理员今日的 AI 工作台模型额度已用完。');
            }
            $adminBudgetReserved = true;
        }

        try {
            $reservation = $this->usageQuota->reserveModel($model);
            if ($reservation === null) {
                throw new AiWorkspaceModelUnavailableException('对话模型不可用或已达到今日限额');
            }

            $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
            $provider = OpenAiRuntimeProvider::registerProvider(
                'admin_help_'.(int) $model->id,
                $driver,
                $providerUrl,
                $apiKey,
            );
        } catch (Throwable $exception) {
            if ($adminBudgetReserved) {
                RateLimiter::decrement((string) $adminBudgetKey, (int) $adminBudgetTtl);
            }

            throw $exception;
        }

        return [$provider, $reservation, $driver];
    }

    private function acquireConcurrencySlot(): CacheRepository
    {
        $cache = Cache::store(app()->environment('testing')
            ? (string) config('cache.default')
            : (string) config('ai-workspace.concurrency_cache_store', 'redis'));
        $lock = $cache->lock('ai-workspace:claim', 10);
        if (! $lock->get()) {
            throw new AiWorkspaceRuntimeGuardException('AI 工作台当前请求较多，请稍后再试。');
        }

        try {
            $modelCalls = max(0, (int) $cache->get('ai-workspace:model-calls', 0));
            if ($modelCalls >= (int) config('ai-workspace.global_concurrency', 10)) {
                throw new AiWorkspaceRuntimeGuardException('AI 工作台已达到全局并发上限。');
            }
            $cache->put('ai-workspace:model-calls', $modelCalls + 1, now()->addMinutes(5));
        } finally {
            $lock->release();
        }

        return $cache;
    }

    private function releaseConcurrencySlot(CacheRepository $cache): void
    {
        $cache->decrement('ai-workspace:model-calls');
    }

    private function withConcurrencySlot(callable $operation): mixed
    {
        $cache = $this->acquireConcurrencySlot();

        try {
            return $operation();
        } finally {
            $this->releaseConcurrencySlot($cache);
        }
    }

    private function remainingAttemptTimeout(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 5) {
            throw new AiWorkspaceRuntimeGuardException('AI 模型调用已达到本轮共享时间预算。');
        }

        return min(
            (int) config('ai-workspace.model_attempt_timeout_seconds', 30),
            max(1, $remaining - 5),
        );
    }

    private function probeTimeout(?int $timeout): int
    {
        return max(1, $timeout ?? (int) config('ai-workspace.model_attempt_timeout_seconds', 30));
    }

    private function answerMaxTokens(AiModel $model): int
    {
        $configured = (int) ($model->max_tokens ?? 0);

        return min(2400, $configured > 0 ? $configured : 2400);
    }

    private function streamCompletedSuccessfully(bool $streamEnded, ?string $finishReason): bool
    {
        return $streamEnded && ! in_array($finishReason, [null, '', 'error', 'unknown'], true);
    }

    private function runtimeException(Throwable $exception, AiModel $model): RuntimeException
    {
        return new RuntimeException(
            OpenAiRuntimeProvider::normalizeApiException($exception, (string) $model->api_url),
            0,
            $exception,
        );
    }

    private function isRecoverableProviderFailure(Throwable $exception): bool
    {
        return ! $exception instanceof AiWorkspaceRuntimeGuardException;
    }

    private function boundedBackoff(int $attempt, float $deadline): void
    {
        $remainingMicroseconds = (int) floor(max(0, $deadline - microtime(true)) * 1_000_000);
        if ($remainingMicroseconds <= 0) {
            return;
        }
        $delay = min($remainingMicroseconds, min(400_000, max(50_000, $attempt * 75_000)));
        usleep($delay);
    }

    private function recordProviderSuccess(AiModel $model): void
    {
        Cache::forget($this->providerCircuitKey($model));
        Cache::forget($this->providerFailureKey($model));
    }

    /** @param array<string, int|null> $performance */
    private function recordReadinessSuccess(AiModel $model, bool $streamingObserved, array $performance = []): void
    {
        try {
            $this->readiness->recordRuntimeSuccess($model, $streamingObserved, $performance);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function elapsedMilliseconds(int $startedAtNanoseconds): int
    {
        return (int) round((hrtime(true) - $startedAtNanoseconds) / 1_000_000);
    }

    private function recordProviderFailure(AiModel $model): void
    {
        $failureKey = $this->providerFailureKey($model);
        $expiresAt = now()->addMinutes(10);
        $added = Cache::add($failureKey, 0, $expiresAt);
        $failures = max(1, (int) Cache::increment($failureKey));
        if (! $added && $failures === 1) {
            Cache::put($failureKey, 1, $expiresAt);
        }
        Cache::put($this->providerCircuitKey($model), true, now()->addSeconds(min(60, 2 ** min($failures, 5))));
    }

    private function providerCircuitKey(AiModel $model): string
    {
        return 'ai-workspace:provider-circuit:'.$this->providerFingerprint($model);
    }

    private function providerFailureKey(AiModel $model): string
    {
        return 'ai-workspace:provider-failures:'.$this->providerFingerprint($model);
    }

    private function providerFingerprint(AiModel $model): string
    {
        return hash('sha256', implode('|', [
            (string) $model->id,
            (string) $model->model_id,
            OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url),
        ]));
    }
}
