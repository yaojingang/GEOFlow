<?php

namespace App\Services\AiWorkspace;

use App\Ai\Agents\AdminHelpAssistant;
use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\Admin\GovernanceAiModelUsageSession;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use App\Support\GeoFlow\AiModelFailoverDecider;
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
        private AiWorkspaceExecutionAccessGuard $executionGuard,
        private AiModelInvocationLock $invocationLocks,
        private AiModelFailoverDecider $failoverDecider,
        private AiExecutionErrorSanitizer $errorSanitizer,
        private AiModelUsageAttemptFactory $usageAttempts,
    ) {}

    /**
     * @param  iterable<int, mixed>  $messages
     * @return Generator<int, array<string, mixed>, mixed, array{answer:string,meta:array<string,mixed>,usage:array<string,int>,completion_receipt:AiWorkspaceModelExecutionReceipt,usage_delivery:AiWorkspaceModelUsageDelivery}>
     */
    public function stream(
        string $prompt,
        string $knowledgeContext,
        iterable $messages = [],
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
    ): Generator {
        $context = $this->executionContext($actor);
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
        $usageRequestId = $this->usageAttempts->requestId();

        try {
            foreach ($this->models($context) as $candidate) {
                $attempts++;
                $model = $candidate;
                $reservation = null;
                $emitted = false;
                $answer = '';
                $streamEnded = false;
                $usage = [];
                $finishReason = null;
                $driver = '';
                $providerName = '';
                $plainTextFallback = false;
                $receipt = null;
                $invocationLock = null;
                $usageAttempt = null;
                $usageDelivery = null;
                $providerReturned = false;

                try {
                    $invocationLock = $this->invocationLocks->acquireForInvocation((int) $candidate->getKey());
                    [$model, $receipt] = $this->executionGuard->claimModelForCall($context, (int) $candidate->getKey());
                    $timeout = $this->remainingAttemptTimeout($deadline);
                    [$provider, $reservation, $driver] = $this->modelContext($model, $context->modelAccessAdminId);
                    $agent = new AdminHelpAssistant(
                        $messages,
                        $knowledgeContext,
                        (string) $model->model_id,
                        $this->answerMaxTokens($model),
                    );

                    $plainTextFallback = $this->readiness->prefersPlainTextFallback($model);
                    $usageAttempt = $this->workspaceUsageAttempt(
                        $context,
                        $model,
                        $usageRequestId,
                        'candidate-'.$attempts,
                        'ai_workspace.stream',
                        $prompt."\n".$knowledgeContext,
                    );
                    if ($plainTextFallback) {
                        $fallbackCount++;
                        $degradedCount++;
                        $response = $agent->prompt($prompt, [], $provider, (string) $model->model_id, $timeout);
                        $providerReturned = true;
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
                        $this->executionGuard->assertReceiptCurrent($context, $receipt);
                        yield [
                            'type' => 'status',
                            'stage' => 'connected',
                            'provider' => $providerName,
                            'model' => (string) $model->model_id,
                        ];
                        yield ['type' => 'delta', 'content' => $answer, 'completion_receipt' => $receipt];
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
                                $this->executionGuard->assertReceiptCurrent($context, $receipt);
                                $emitted = true;
                                $firstTextMilliseconds ??= $this->elapsedMilliseconds($startedAtNanoseconds);
                                $answer .= $event->delta;
                                yield ['type' => 'delta', 'content' => $event->delta, 'completion_receipt' => $receipt];

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
                        $providerReturned = $streamEnded;
                        $answer = trim((string) $stream->text) ?: trim($answer);
                        if (! $this->streamCompletedSuccessfully($streamEnded, $finishReason)) {
                            throw new RuntimeException('AI 模型流式响应未正常完成。');
                        }
                    }
                    if ($answer === '') {
                        throw new RuntimeException('AI 模型未返回文本内容。');
                    }
                    $this->executionGuard->assertReceiptCurrent($context, $receipt);
                    $this->executionGuard->recordResolvedModel($context, $model);
                    $this->usageQuota->recordModelSuccess($reservation);
                    $this->recordProviderSuccess($model);
                    $totalMilliseconds = $this->elapsedMilliseconds($startedAtNanoseconds);
                    $performance = [
                        'provider_first_event_ms' => $firstProviderEventMilliseconds,
                        'ttft_ms' => $firstTextMilliseconds,
                        'total_ms' => $totalMilliseconds,
                    ];
                    $this->recordReadinessSuccess($model, ! $plainTextFallback, $performance);
                    $usageDelivery = new AiWorkspaceModelUsageDelivery($usageAttempt, $usage);

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
                        'completion_receipt' => $receipt,
                        'usage_delivery' => $usageDelivery,
                    ];
                } catch (Throwable $exception) {
                    if ($exception instanceof AiModelAccessException) {
                        $usageAttempt?->revoked($exception->getErrorCode(), $usage);

                        throw $exception;
                    }
                    if ($exception instanceof AiWorkspaceRuntimeGuardException) {
                        $this->finalizeWorkspaceFailure(
                            $usageAttempt,
                            $exception,
                            $providerReturned,
                            $usage,
                        );

                        throw $exception;
                    }
                    if ($this->streamCompletedSuccessfully($streamEnded, $finishReason) && trim($answer) !== '') {
                        try {
                            if (! $receipt instanceof AiWorkspaceModelExecutionReceipt) {
                                throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
                            }
                            $this->executionGuard->assertReceiptCurrent($context, $receipt);
                            $this->executionGuard->recordResolvedModel($context, $model);
                        } catch (AiModelAccessException $accessException) {
                            $usageAttempt?->revoked($accessException->getErrorCode(), $usage);

                            throw $accessException;
                        }
                        report(new RuntimeException($this->errorSanitizer->sanitize($exception)));
                        $this->usageQuota->recordModelSuccess($reservation);
                        $this->recordProviderSuccess($model);
                        $totalMilliseconds = $this->elapsedMilliseconds($startedAtNanoseconds);
                        $performance = [
                            'provider_first_event_ms' => $firstProviderEventMilliseconds,
                            'ttft_ms' => $firstTextMilliseconds,
                            'total_ms' => $totalMilliseconds,
                        ];
                        $this->recordReadinessSuccess($model, true, $performance);
                        if (! $usageAttempt instanceof AiModelUsageAttempt) {
                            throw new RuntimeException('AI usage attempt is unavailable.');
                        }
                        $usageDelivery = new AiWorkspaceModelUsageDelivery($usageAttempt, $usage);

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
                            'completion_receipt' => $receipt,
                            'usage_delivery' => $usageDelivery,
                        ];
                    }
                    $this->finalizeWorkspaceFailure(
                        $usageAttempt,
                        $exception,
                        $providerReturned,
                        $usage,
                    );
                    if ($exception instanceof PermanentAiProviderException) {
                        throw $exception;
                    }
                    $lastException = $this->runtimeException($exception, $model);
                    if ($this->failoverDecider->isPermanentProviderFailure($exception)) {
                        throw PermanentAiProviderException::fromProviderFailure($lastException);
                    }
                    $recoverable = $this->isRecoverableProviderFailure($exception);
                    if ($emitted) {
                        $this->recordProviderFailure($model);
                        throw $lastException;
                    }
                    if (! $recoverable) {
                        if ($exception instanceof AiWorkspaceRuntimeGuardException) {
                            throw $exception;
                        }

                        throw PermanentAiProviderException::fromProviderFailure($lastException);
                    }
                    $fallbackCount++;
                    if (! $exception instanceof AiWorkspaceModelUnavailableException) {
                        $this->recordProviderFailure($model);
                        $this->boundedBackoff($attempts, $deadline);
                    }
                } finally {
                    if (! $usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                        $usageAttempt?->discarded('ai_result_not_delivered', $usage);
                    }
                    $this->invocationLocks->release($invocationLock);
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
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
        ?callable $onResolved = null,
    ): string {
        $context = $this->executionContext($actor);

        $result = $this->answerResult($prompt, $knowledgeContext, $messages, $context);
        $delivery = $result['usage_delivery'];

        try {
            if ($onResolved !== null) {
                $onResolved($result['completion_receipt']);
            }
            $delivery->succeeded();

            return $result['answer'];
        } catch (AiModelAccessException $exception) {
            $delivery->revoked($exception->getErrorCode());

            throw $exception;
        } catch (Throwable $exception) {
            $delivery->discarded('ai_result_not_committed');

            throw $exception;
        }
    }

    /**
     * @param  iterable<int,mixed>  $messages
     * @return array{answer:string,completion_receipt:AiWorkspaceModelExecutionReceipt,usage_delivery:AiWorkspaceModelUsageDelivery}
     */
    private function answerResult(
        string $prompt,
        string $knowledgeContext,
        iterable $messages,
        AiWorkspaceExecutionContext $context,
    ): array {

        return $this->withConcurrencySlot(function () use ($prompt, $knowledgeContext, $messages, $context): array {
            $lastException = null;
            $attempt = 0;
            $deadline = microtime(true) + (int) config('ai-workspace.model_total_timeout_seconds', 90);
            $usageRequestId = $this->usageAttempts->requestId();

            foreach ($this->models($context) as $candidate) {
                $attempt++;
                $model = $candidate;
                $reservation = null;
                $invocationLock = null;
                $usageAttempt = null;
                $providerReturned = false;
                $usage = [];
                $usageDelivery = null;
                try {
                    $invocationLock = $this->invocationLocks->acquireForInvocation((int) $candidate->getKey());
                    [$model, $receipt] = $this->executionGuard->claimModelForCall($context, (int) $candidate->getKey());
                    $timeout = $this->remainingAttemptTimeout($deadline);
                    [$provider, $reservation] = $this->modelContext($model, $context->modelAccessAdminId);
                    $agent = new AdminHelpAssistant(
                        $messages,
                        $knowledgeContext,
                        (string) $model->model_id,
                        $this->answerMaxTokens($model),
                    );
                    $usageAttempt = $this->workspaceUsageAttempt(
                        $context,
                        $model,
                        $usageRequestId,
                        'candidate-'.$attempt,
                        'ai_workspace.answer',
                        $prompt."\n".$knowledgeContext,
                    );
                    $response = $agent->prompt($prompt, [], $provider, (string) $model->model_id, $timeout);
                    $providerReturned = true;
                    $usage = $response->usage->toArray();
                    $answer = trim((string) $response->text);
                    if ($answer === '') {
                        throw new RuntimeException('AI 模型未返回文本内容。');
                    }
                    $this->executionGuard->assertReceiptCurrent($context, $receipt);
                    $this->executionGuard->recordResolvedModel($context, $model);
                    $this->usageQuota->recordModelSuccess($reservation);
                    $this->recordProviderSuccess($model);
                    $this->recordReadinessSuccess($model, false);
                    $usageDelivery = new AiWorkspaceModelUsageDelivery($usageAttempt, $usage);

                    return [
                        'answer' => $answer,
                        'completion_receipt' => $receipt,
                        'usage_delivery' => $usageDelivery,
                    ];
                } catch (Throwable $exception) {
                    $this->finalizeWorkspaceFailure(
                        $usageAttempt,
                        $exception,
                        $providerReturned,
                        $usage,
                    );
                    if ($reservation !== null) {
                        $this->usageQuota->recordModelAttempt($reservation);
                    }
                    if ($exception instanceof AiModelAccessException || $exception instanceof PermanentAiProviderException) {
                        throw $exception;
                    }
                    $lastException = $this->runtimeException($exception, $model);
                    if ($this->failoverDecider->isPermanentProviderFailure($exception)) {
                        throw PermanentAiProviderException::fromProviderFailure($lastException);
                    }
                    if (! $this->isRecoverableProviderFailure($exception)) {
                        if ($exception instanceof AiWorkspaceRuntimeGuardException) {
                            throw $exception;
                        }

                        throw PermanentAiProviderException::fromProviderFailure($lastException);
                    }
                    if (! $exception instanceof AiWorkspaceModelUnavailableException) {
                        $this->recordProviderFailure($model);
                        $this->boundedBackoff($attempt, $deadline);
                    }
                } finally {
                    if (! $usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                        $usageAttempt?->discarded('ai_result_not_delivered', $usage);
                    }
                    $this->invocationLocks->release($invocationLock);
                }
            }

            throw $lastException ?? new RuntimeException('没有可用的对话模型');
        });
    }

    /** @return array<string,mixed> */
    public function resolveIntent(
        string $prompt,
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
        ?callable $onComplete = null,
    ): array {
        $context = $this->executionContext($actor);
        $result = $this->answerResult(
            $prompt,
            '请将请求解析为受控工作台意图 JSON。',
            [],
            $context,
        );
        $delivery = $result['usage_delivery'];
        try {
            $decoded = json_decode($result['answer'], true);
            $intent = is_array($decoded) ? $decoded : [];
            if ($onComplete !== null) {
                $onComplete(['stage' => 'intent', 'completed' => true], $result['completion_receipt'], $delivery);
            } else {
                $delivery->succeeded();
            }

            return $intent;
        } catch (AiModelAccessException $exception) {
            $delivery->revoked($exception->getErrorCode());

            throw $exception;
        } catch (Throwable $exception) {
            $delivery->discarded('ai_result_not_committed');

            throw $exception;
        }
    }

    /** @param array<string,mixed> $resolution @return list<array<string,mixed>> */
    public function draftPlan(
        string $prompt,
        array $resolution,
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
        ?callable $onComplete = null,
    ): array {
        $context = $this->executionContext($actor);
        $result = $this->answerResult(
            $prompt,
            '请依据已解析意图生成受控步骤 JSON：'.json_encode($resolution, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            [],
            $context,
        );
        $delivery = $result['usage_delivery'];
        try {
            $decoded = json_decode($result['answer'], true);
            $steps = is_array($decoded) ? (array) ($decoded['steps'] ?? $decoded) : [];
            if ($onComplete !== null) {
                $onComplete(['stage' => 'plan', 'completed' => true], $result['completion_receipt'], $delivery);
            } else {
                $delivery->succeeded();
            }

            return array_values(array_filter($steps, 'is_array'));
        } catch (AiModelAccessException $exception) {
            $delivery->revoked($exception->getErrorCode());

            throw $exception;
        } catch (Throwable $exception) {
            $delivery->discarded('ai_result_not_committed');

            throw $exception;
        }
    }

    /**
     * @param  iterable<int,mixed>  $messages
     */
    public function streamAnswer(
        string $prompt,
        callable $onDelta,
        iterable $messages = [],
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
        ?callable $onComplete = null,
    ): string {
        $stream = $this->stream($prompt, '', $messages, $actor);
        foreach ($stream as $event) {
            if (is_array($event) && ($event['type'] ?? null) === 'delta') {
                $receipt = $event['completion_receipt'] ?? null;
                if (! $receipt instanceof AiWorkspaceModelExecutionReceipt) {
                    throw AiModelAccessException::configAccessRevokedForAdminId(
                        $actor instanceof AiWorkspaceExecutionContext ? $actor->modelAccessAdminId : 0,
                    );
                }
                $onDelta((string) ($event['content'] ?? ''), $receipt);
            }
        }
        $result = $stream->getReturn();
        $delivery = is_array($result) ? ($result['usage_delivery'] ?? null) : null;
        if ($onComplete !== null) {
            $onComplete(
                is_array($result) ? (array) ($result['meta'] ?? []) : [],
                is_array($result) ? ($result['completion_receipt'] ?? null) : null,
                $delivery,
            );
        } elseif ($delivery instanceof AiWorkspaceModelUsageDelivery) {
            $delivery->succeeded();
        }

        return is_array($result) ? trim((string) ($result['answer'] ?? '')) : trim((string) $result);
    }

    /** @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,raw_preview:string} */
    public function probePlainText(
        AiModel $model,
        string $prompt,
        ?int $timeout = null,
        ?GovernanceAiModelUsageSession $usageSession = null,
    ): array {
        try {
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
            $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
            $modelId = trim((string) $model->model_id);
        } catch (Throwable) {
            throw PermanentAiProviderException::fromProviderFailure(
                new RuntimeException('ai_model_configuration_invalid'),
            );
        }
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw PermanentAiProviderException::fromProviderFailure(
                new RuntimeException('ai_model_configuration_invalid'),
            );
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
        $callKey = 'plain.p2';
        $agent = new AdminHelpAssistant([], '模型连接检测。', $modelId);
        try {
            $response = $this->withConcurrencySlot(function () use (
                $agent,
                $callKey,
                $driver,
                $model,
                $modelId,
                $prompt,
                $provider,
                $timeout,
                $usageSession,
            ): object {
                $usageSession?->begin(
                    $model,
                    $callKey,
                    $this->probePayload('plain', $driver, $modelId, $prompt, $agent),
                );

                return $agent->prompt($prompt, [], $provider, $modelId, $timeout);
            });
        } catch (Throwable $exception) {
            $usageSession?->markFailed($callKey);

            throw $exception;
        }
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $text = trim((string) $response->text);
        if ($text === '') {
            $usageSession?->markDiscarded($callKey);
            throw new RuntimeException('AI 工作台普通文本检测没有返回内容。');
        }
        $usageSession?->retainUsage($callKey, $response->usage ?? null);

        return [
            'provider' => $driver,
            'endpoint' => $providerUrl,
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'raw_preview' => Str::limit($text, 500, ''),
        ];
    }

    /** @return array{provider:string,endpoint:string,http_status:int,latency_ms:int,raw_preview:string,delta_count:int,usage:mixed} */
    public function probeStreaming(
        AiModel $model,
        string $prompt,
        ?int $timeout = null,
        ?GovernanceAiModelUsageSession $usageSession = null,
    ): array {
        try {
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
            $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
            $modelId = trim((string) $model->model_id);
        } catch (Throwable) {
            throw PermanentAiProviderException::fromProviderFailure(
                new RuntimeException('ai_model_configuration_invalid'),
            );
        }
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw PermanentAiProviderException::fromProviderFailure(
                new RuntimeException('ai_model_configuration_invalid'),
            );
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
        $callKey = 'stream.p1';
        $agent = new AdminHelpAssistant([], '模型流式连接检测。', $modelId);
        try {
            $result = $this->withConcurrencySlot(function () use (
                $agent,
                $callKey,
                $driver,
                $model,
                $modelId,
                $prompt,
                $provider,
                $timeout,
                $usageSession,
            ): array {
                $usageSession?->begin(
                    $model,
                    $callKey,
                    $this->probePayload('stream', $driver, $modelId, $prompt, $agent),
                );
                $stream = $agent->stream($prompt, [], $provider, $modelId, $timeout);
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
                        throw new RuntimeException('ai_provider_stream_error');
                    }
                }

                return [
                    'text' => trim((string) $stream->text) ?: trim($text),
                    'delta_count' => $deltaCount,
                    'stream_ended' => $streamEnded,
                    'finish_reason' => $finishReason,
                    'usage' => $stream->usage,
                ];
            });
        } catch (Throwable $exception) {
            $usageSession?->markFailed($callKey);

            throw $exception;
        }
        $usageSession?->retainUsage($callKey, $result['usage'] ?? null);
        if ((string) $result['text'] === ''
            || (int) $result['delta_count'] === 0
            || ! $this->streamCompletedSuccessfully(
                (bool) $result['stream_ended'],
                is_string($result['finish_reason']) ? $result['finish_reason'] : null,
            )) {
            $usageSession?->markDiscarded($callKey);
            throw new RuntimeException('AI 工作台流式检测没有返回正文分片。');
        }
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return [
            'provider' => $driver,
            'endpoint' => $providerUrl,
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'raw_preview' => Str::limit((string) $result['text'], 500, ''),
            'delta_count' => (int) $result['delta_count'],
            'usage' => $result['usage'] ?? null,
        ];
    }

    private function probePayload(
        string $mode,
        string $driver,
        string $modelId,
        string $prompt,
        AdminHelpAssistant $agent,
    ): string {
        return json_encode([
            'mode' => $mode,
            'provider' => $driver,
            'model' => $modelId,
            'instructions' => $agent->instructions(),
            'provider_options' => $agent->providerOptions($driver),
            'prompt' => $prompt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return iterable<int, AiModel> */
    private function models(AiWorkspaceExecutionContext $context): iterable
    {
        $firstAttemptable = null;
        $yielded = false;
        foreach ($this->executionGuard->resolveCandidates($context) as $model) {
            if ($this->readiness->hasPermanentFailure($model)) {
                throw PermanentAiProviderException::fromProviderFailure(
                    new RuntimeException('ai_model_readiness_rejected'),
                );
            }
            if (! $this->readiness->canAttempt($model)) {
                continue;
            }
            $firstAttemptable ??= $model;
            if (Cache::has($this->providerCircuitKey($model))) {
                continue;
            }
            $yielded = true;
            yield $model;
        }

        if (! $yielded && $firstAttemptable instanceof AiModel) {
            yield $firstAttemptable;
        }
    }

    private function workspaceUsageAttempt(
        AiWorkspaceExecutionContext $context,
        AiModel $model,
        string $requestId,
        string $callKey,
        string $operation,
        string $requestPayload,
    ): AiModelUsageAttempt {
        return $this->usageAttempts->beginForAdmin(
            model: $model,
            executionAdminId: $context->modelAccessAdminId,
            accessVersion: $context->aiConfigAccessVersion,
            executionScope: $context->runId === null
                ? AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN
                : AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
            modelSource: $this->usageAttempts->sourceFor($model, $context->modelAccessAdminId),
            requestId: $requestId,
            requestPayload: $requestPayload,
            callKey: $callKey,
            operation: $operation,
            businessSource: 'ai_workspace',
            sourceType: $context->runId === null ? 'ai_workspace_direct' : 'ai_workspace_run',
            sourceId: $context->runId ?? $context->modelAccessAdminId,
        );
    }

    /** @param array<string,mixed> $usage */
    private function finalizeWorkspaceFailure(
        ?AiModelUsageAttempt $usageAttempt,
        Throwable $exception,
        bool $providerReturned,
        array $usage,
    ): void {
        if (! $usageAttempt instanceof AiModelUsageAttempt || $usageAttempt->isFinalized()) {
            return;
        }
        if ($exception instanceof AiModelAccessException) {
            $usageAttempt->revoked($exception->getErrorCode(), $usage);

            return;
        }
        if ($exception instanceof AiWorkspaceRuntimeGuardException) {
            $usageAttempt->discarded('ai_result_not_committed', $usage);

            return;
        }
        $providerReturned
            ? $usageAttempt->discarded('ai_result_not_committed', $usage)
            : $usageAttempt->failed('ai_provider_request_failed', $usage);
    }

    private function executionContext(Admin|AiWorkspaceExecutionContext|int|null $actor): AiWorkspaceExecutionContext
    {
        if ($actor instanceof AiWorkspaceExecutionContext) {
            return $actor;
        }
        $actorId = is_int($actor) ? $actor : 0;
        if (is_int($actor)) {
            $actor = Admin::query()->find($actor);
        }
        if (! $actor instanceof Admin) {
            throw AiModelAccessException::executionAdminInactiveForId($actorId);
        }

        return $this->executionGuard->directContext($actor);
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

        try {
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
            $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key'));
            $modelId = trim((string) $model->model_id);
        } catch (Throwable) {
            throw PermanentAiProviderException::fromProviderFailure(
                new RuntimeException('ai_model_configuration_invalid'),
            );
        }
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw PermanentAiProviderException::fromProviderFailure(
                new RuntimeException('ai_model_configuration_invalid'),
            );
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

            if (! $exception instanceof AiWorkspaceModelUnavailableException
                && ! $exception instanceof AiWorkspaceRuntimeGuardException
                && ! $exception instanceof PermanentAiProviderException) {
                throw PermanentAiProviderException::fromProviderFailure(
                    new RuntimeException('ai_model_configuration_invalid'),
                );
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
            $this->errorSanitizer->sanitize(
                OpenAiRuntimeProvider::normalizeApiException($exception, (string) $model->api_url),
                'AI provider request failed',
            ),
        );
    }

    private function isRecoverableProviderFailure(Throwable $exception): bool
    {
        return $exception instanceof AiWorkspaceModelUnavailableException
            || $this->failoverDecider->shouldFailover($exception)
            || str_contains($exception->getMessage(), '未返回文本内容')
            || str_contains($exception->getMessage(), '流式响应未正常完成');
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
            report(new RuntimeException($this->errorSanitizer->sanitize($exception)));
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
