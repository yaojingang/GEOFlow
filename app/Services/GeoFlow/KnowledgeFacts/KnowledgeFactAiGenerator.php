<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Ai\Agents\KnowledgeFactGeneratorAgent;
use App\Data\Ai\KnowledgeFactGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\KnowledgeFactAiGenerationException;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use RuntimeException;
use Throwable;

class KnowledgeFactAiGenerator
{
    public function __construct(
        private readonly ApiKeyCrypto $crypto,
        private readonly AiUsageQuotaService $quota,
        private readonly AiWorkspaceModelReadiness $readiness,
        private readonly KnowledgeFactStableKeyPolicy $stableKeyPolicy,
        private readonly KnowledgeFactGenerationAiExecutionGuard $executionGuard,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly AiModelFailoverDecider $failoverDecider,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
    ) {}

    /** @param list<array<string,string>> $evidence @return list<array<string,mixed>> */
    public function generate(
        AiModel $model,
        array $evidence,
        int $count,
        ?KnowledgeFactGenerationExecutionContext $executionContext = null,
        ?Closure $persistResult = null,
        ?string $usageRequestId = null,
        string $usageCallKey = 'candidate-1',
    ): array {
        $invocationLock = null;
        $reservation = null;
        $requested = false;
        $finalized = false;
        $providerReturned = false;
        $usageAttempt = null;
        $responseUsage = null;
        try {
            $invocationLock = $this->invocationLocks->acquireForInvocation((int) $model->getKey());
            $invocationModel = $this->currentModelForInvocation($model, $executionContext);
            if (data_get($invocationModel->ai_workspace_readiness_profile, 'knowledge_fact_structured_output.status') === 'unsupported') {
                throw new RuntimeException('knowledge_fact_structured_output_unsupported');
            }
            $reservation = $this->quota->reserveModel($invocationModel);
            if ($reservation === null) {
                throw new RuntimeException('ai_quota_exhausted');
            }
            $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $invocationModel->api_url);
            $key = $this->crypto->decrypt((string) $invocationModel->getRawOriginal('api_key'));
            if ($baseUrl === '' || $key === '') {
                throw new RuntimeException('ai_model_configuration_invalid');
            }
            $provider = OpenAiRuntimeProvider::registerProvider('knowledge_facts', OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) $invocationModel->model_id), $baseUrl, $key);
            $configurationFingerprint = $this->readiness->configurationFingerprint($invocationModel);
            $prePromptModel = $this->currentModelForInvocation($invocationModel, $executionContext);
            $this->assertConfigurationUnchanged(
                $configurationFingerprint,
                $prePromptModel,
                $executionContext,
            );
            $prompt = "最多提取 {$count} 条事实。只使用以下 JSON 证据：\n".json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($executionContext instanceof KnowledgeFactGenerationExecutionContext) {
                $usageAttempt = $this->usageAttempts->beginForAdmin(
                    model: $prePromptModel,
                    executionAdminId: $executionContext->modelAccessAdminId,
                    accessVersion: $executionContext->aiConfigAccessVersion,
                    executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                    modelSource: $this->usageAttempts->sourceFor(
                        $prePromptModel,
                        $executionContext->modelAccessAdminId,
                    ),
                    requestId: $usageRequestId ?? $this->usageAttempts->requestId(),
                    requestPayload: $prompt,
                    callKey: $usageCallKey,
                    operation: 'knowledge_fact.generate',
                    businessSource: 'knowledge_fact_generation',
                    sourceType: 'knowledge_fact_generation_run',
                    sourceId: $executionContext->runId,
                );
            }
            $requested = true;
            $response = (new KnowledgeFactGeneratorAgent)->prompt($prompt, [], $provider, (string) $invocationModel->model_id, 150);
            $providerReturned = true;
            $responseUsage = $response->usage ?? null;
            $facts = is_array($response->structured['facts'] ?? null) ? array_slice($response->structured['facts'], 0, $count) : [];
            $allowed = array_column($evidence, 'evidence_key');
            $facts = array_values(array_filter(array_map(fn (mixed $fact): ?array => $this->normalizeCandidate($fact, $allowed), $facts)));
            DB::transaction(function () use ($invocationModel, $executionContext, $reservation, $configurationFingerprint): void {
                $current = $this->currentModelForInvocation($invocationModel, $executionContext);
                $this->assertConfigurationUnchanged(
                    $configurationFingerprint,
                    $current,
                    $executionContext,
                );
                $this->quota->recordModelSuccess($reservation);
                try {
                    $profile = (array) $current->ai_workspace_readiness_profile;
                    $profile['knowledge_fact_structured_output'] = ['status' => 'ready', 'observed' => true, 'last_success_at' => now()->toIso8601String(), 'configuration_fingerprint' => $this->readiness->configurationFingerprint($current)];
                    $current->forceFill(['ai_workspace_readiness_profile' => $profile])->save();
                } catch (AiModelAccessException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }, 3);
            if ($persistResult instanceof Closure) {
                $current = $this->currentModelForInvocation($invocationModel, $executionContext);
                $this->assertConfigurationUnchanged(
                    $configurationFingerprint,
                    $current,
                    $executionContext,
                );
                $persisted = DB::transaction(
                    fn (): mixed => $persistResult($facts, $current),
                    3,
                );
                if (is_array($persisted)) {
                    $facts = array_values($persisted);
                }
            }
            $finalized = true;
            $usageAttempt?->succeeded($responseUsage);

            return $facts;
        } catch (Throwable $exception) {
            if ($usageAttempt instanceof AiModelUsageAttempt) {
                if ($exception instanceof AiModelAccessException) {
                    $usageAttempt->revoked($exception->getErrorCode(), $responseUsage);
                } elseif ($providerReturned) {
                    $usageAttempt->discarded('knowledge_fact_result_not_committed', $responseUsage);
                } else {
                    $usageAttempt->failed('ai_provider_request_failed');
                }
            }
            if ($reservation !== null && ! $finalized) {
                $requested
                    ? $this->quota->recordModelAttempt($reservation)
                    : $this->quota->releaseModel($reservation);
            }
            if ($exception instanceof AiModelAccessException
                || $exception instanceof KnowledgeFactAiGenerationException) {
                throw $exception;
            }

            throw new KnowledgeFactAiGenerationException(
                ! $this->containsInsufficientCredits($exception)
                    && $this->failoverDecider->shouldFailover($exception),
            );
        } finally {
            $usageAttempt?->discarded('knowledge_fact_result_not_committed', $responseUsage);
            $this->invocationLocks->release($invocationLock);
        }
    }

    private function currentModelForInvocation(
        AiModel $model,
        ?KnowledgeFactGenerationExecutionContext $executionContext,
    ): AiModel {
        if ($executionContext instanceof KnowledgeFactGenerationExecutionContext) {
            $this->executionGuard->assertCurrent($executionContext, (int) $model->getKey());
        }

        $current = AiModel::query()->whereKey($model->getKey())->first();
        if (! $current instanceof AiModel || (string) $current->status !== 'active') {
            if ($executionContext instanceof KnowledgeFactGenerationExecutionContext) {
                throw AiModelAccessException::configAccessRevokedForAdminId(
                    $executionContext->modelAccessAdminId,
                );
            }

            throw new RuntimeException('ai_model_unavailable');
        }

        return $current;
    }

    private function assertConfigurationUnchanged(
        string $expectedFingerprint,
        AiModel $current,
        ?KnowledgeFactGenerationExecutionContext $executionContext,
    ): void {
        if (hash_equals(
            $expectedFingerprint,
            $this->readiness->configurationFingerprint($current),
        )) {
            return;
        }

        if ($executionContext instanceof KnowledgeFactGenerationExecutionContext) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                $executionContext->modelAccessAdminId,
            );
        }

        throw new RuntimeException('ai_model_configuration_changed');
    }

    private function containsInsufficientCredits(Throwable $exception): bool
    {
        $current = $exception;
        for ($depth = 0; $depth < 8; $depth++) {
            if ($current instanceof InsufficientCreditsException) {
                return true;
            }
            $current = $current->getPrevious();
            if (! $current instanceof Throwable) {
                return false;
            }
        }

        return false;
    }

    /** @param list<string> $allowed @return array<string,mixed>|null */
    private function normalizeCandidate(mixed $fact, array $allowed): ?array
    {
        if (! is_array($fact) || mb_strlen(json_encode($fact, JSON_UNESCAPED_UNICODE) ?: '') > 12000) {
            return null;
        }
        $limits = ['stable_key' => 160, 'label' => 255, 'subject' => 255, 'predicate' => 255, 'canonical_value' => 2000, 'canonical_answer' => 5000, 'unit' => 64];
        foreach ($limits as $field => $limit) {
            if (! is_string($fact[$field] ?? null) || mb_strlen($fact[$field]) > $limit) {
                return null;
            }
        }
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,159}\z/', $fact['stable_key']) !== 1
            || ! in_array((string) ($fact['value_type'] ?? ''), ['string', 'integer', 'decimal', 'number', 'date', 'boolean', 'url'], true)) {
            return null;
        }
        $fact['stable_key'] = $this->stableKeyPolicy->normalize(
            $fact['stable_key'],
            $fact['subject'],
            $fact['predicate'],
            $fact['label'],
        );
        if (in_array($fact['value_type'], ['integer', 'decimal', 'number'], true)
            && preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', $fact['canonical_value']) !== 1) {
            return null;
        }
        $keys = array_values(array_unique(array_filter((array) ($fact['evidence_keys'] ?? []), 'is_string')));
        if ($keys === [] || count($keys) > 20 || array_diff($keys, $allowed) !== []) {
            return null;
        }
        $fact['evidence_keys'] = $keys;
        $fact['temporal_kind'] = in_array((string) ($fact['temporal_kind'] ?? ''), ['timeless', 'observed', 'interval'], true) ? $fact['temporal_kind'] : 'timeless';
        foreach (['valid_from', 'valid_to', 'observed_at', 'scope_entity', 'scope_region', 'scope_channel', 'statistic_definition', 'comparison_tolerance'] as $field) {
            $fact[$field] = is_string($fact[$field] ?? null) ? mb_substr(trim($fact[$field]), 0, 255) : '';
        }
        foreach (['valid_from', 'valid_to'] as $field) {
            if ($fact[$field] !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $fact[$field]) !== 1) {
                return null;
            }
        }
        if ($fact['observed_at'] !== '' && strtotime($fact['observed_at']) === false) {
            return null;
        }

        return $fact;
    }
}
