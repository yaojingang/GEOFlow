<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\AiWorkspace\AiWorkspaceModelUnavailableException;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiUsageReservation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class AiVisibilityService
{
    public function __construct(
        private readonly DoubaoArkResponsesClient $doubaoArkResponsesClient,
        private readonly DoubaoSearchCustomClient $doubaoSearchCustomClient,
        private readonly DeepSeekAnalysisClient $deepSeekAnalysisClient,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
        private readonly AiVisibilityModelExecutionGuard $executionGuard,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
        private readonly AiModelInvocationLock $invocationLocks,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function runDoubaoArkResponses(SystemAiIdentity $identity, AiModel $model, string $keyword, ?string $prompt = null, array $options = []): AiVisibilityRun
    {
        $identity->assertCanCollectVisibility();
        $keyword = $this->normalizeKeyword($keyword);
        $prompt = $this->resolvePrompt($keyword, $prompt);
        $run = $this->createRun([
            'keyword' => $keyword,
            'prompt' => $prompt,
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
            'provider_key' => 'doubao_ark',
            'ai_model_id' => (int) $model->id,
            'model_id' => (string) ($model->model_id ?? ''),
            'locale' => (string) ($options['locale'] ?? 'zh_CN'),
        ]);

        return $this->runModelCall(
            identity: $identity,
            run: $run,
            model: $model,
            bindingType: 'ark',
            callKeyPrefix: 'ark',
            operation: 'ai_visibility.collect.ark',
            maxProviderAttempts: max(1, (int) config('geoflow.ai_visibility.http_retry_attempts', 2)),
            requestPayload: fn (AiModel $current): AiVisibilityPreparedModelRequest => $this->doubaoArkResponsesClient
                ->prepareRequest($current, $prompt, $options),
            provider: fn (AiModel $current, AiVisibilityPreparedModelRequest $prepared): AiVisibilityResult => $this->doubaoArkResponsesClient
                ->answerWithWebSearch($current, $prompt, $options, $prepared),
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function runDoubaoSearchCustom(AiSourceProvider $provider, string $keyword, array $options = []): AiVisibilityRun
    {
        $keyword = $this->normalizeKeyword($keyword);
        $run = $this->createRun([
            'keyword' => $keyword,
            'prompt' => (string) ($options['prompt'] ?? $keyword),
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'provider_key' => (string) ($provider->provider_key ?? AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM),
            'ai_source_provider_id' => (int) $provider->id,
            'locale' => (string) ($options['locale'] ?? 'zh_CN'),
        ]);

        $reservation = null;
        try {
            $this->assertSourceProviderEnabled($provider, '豆包 Search Custom 信源供应商');
            $reservation = $this->usageQuota->reserveProvider($provider);
            if ($reservation === null) {
                throw new RuntimeException('ai_source_provider_quota_exhausted');
            }
            $result = $this->doubaoSearchCustomClient->search(
                $provider,
                $keyword,
                array_replace($provider->visibilitySearchOptions(), $options),
            );

            return $this->completeRun($run, $result, providerReservation: $reservation);
        } catch (Throwable $exception) {
            if ($reservation !== null) {
                $this->usageQuota->releaseProvider($reservation);
            }
            $errorCode = $this->safeErrorCode($exception);
            $this->failRun($run, $errorCode);
            throw new RuntimeException($errorCode);
        }
    }

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @param  array<string,mixed>  $options
     */
    public function runDeepSeekAnalysis(SystemAiIdentity $identity, AiModel $model, string $keyword, string $prompt, array $sources = [], array $options = []): AiVisibilityRun
    {
        $identity->assertCanCollectVisibility();
        $keyword = $this->normalizeKeyword($keyword);
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('ai_visibility_prompt_missing');
        }
        $run = $this->createRun([
            'keyword' => $keyword,
            'prompt' => $prompt,
            'provider_type' => AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
            'provider_key' => 'deepseek',
            'ai_model_id' => (int) $model->id,
            'model_id' => (string) ($model->model_id ?? ''),
            'locale' => (string) ($options['locale'] ?? 'zh_CN'),
        ]);

        return $this->runModelCall(
            identity: $identity,
            run: $run,
            model: $model,
            bindingType: 'deepseek',
            callKeyPrefix: 'deepseek',
            operation: 'ai_visibility.collect.deepseek',
            maxProviderAttempts: 1,
            requestPayload: fn (): AiVisibilityPreparedModelRequest => $this->deepSeekAnalysisClient
                ->prepareRequest($prompt, $sources),
            provider: fn (AiModel $current, AiVisibilityPreparedModelRequest $prepared): AiVisibilityResult => $this->deepSeekAnalysisClient
                ->analyze($current, $prompt, $sources, $options, $prepared),
        );
    }

    public function runCompetitorDetection(SystemAiIdentity $identity, AiModel $model, AiVisibilityRun $source, string $prompt, ?string $executionUuid = null): AiVisibilityRun
    {
        $identity->assertCanCollectVisibility();
        $run = $this->createRun([
            'uuid' => $executionUuid,
            'parent_run_id' => $source->id,
            'keyword' => $source->keyword,
            'prompt' => $prompt,
            'provider_type' => AiVisibilityRun::PROVIDER_COMPETITOR_DETECTION,
            'provider_key' => 'deepseek',
            'ai_model_id' => $model->id,
            'model_id' => $model->model_id,
        ]);

        return $this->runModelCall(
            identity: $identity,
            run: $run,
            model: $model,
            bindingType: 'deepseek',
            callKeyPrefix: 'competitor',
            operation: 'ai_visibility.detect_competitors',
            maxProviderAttempts: 1,
            requestPayload: fn (): AiVisibilityPreparedModelRequest => $this->deepSeekAnalysisClient->prepareRequest($prompt, []),
            provider: function (AiModel $current, AiVisibilityPreparedModelRequest $prepared) use ($prompt): AiVisibilityResult {
                $result = $this->deepSeekAnalysisClient->analyze($current, $prompt, [], [], $prepared);
                app(AiVisibilityCompetitorParser::class)->parse($result->answerText);

                return $result;
            },
        );
    }

    /**
     * @param  array<string,mixed>  $searchOptions
     * @param  array<string,mixed>  $analysisOptions
     * @return array{search_run:AiVisibilityRun,analysis_run:AiVisibilityRun}
     */
    public function runDoubaoSearchThenDeepSeekAnalysis(
        SystemAiIdentity $identity,
        AiSourceProvider $sourceProvider,
        AiModel $analysisModel,
        string $keyword,
        ?string $analysisPrompt = null,
        array $searchOptions = [],
        array $analysisOptions = [],
    ): array {
        $identity->assertCanCollectVisibility();
        $searchRun = $this->runDoubaoSearchCustom($sourceProvider, $keyword, $searchOptions);
        $searchRun->load('sources');
        $sources = $this->sourceDataFromRun($searchRun);
        $prompt = $analysisPrompt !== null && trim($analysisPrompt) !== ''
            ? $analysisPrompt
            : $this->defaultAnalysisPrompt($keyword);

        $analysisRun = $this->runDeepSeekAnalysis($identity, $analysisModel, $keyword, $prompt, $sources, $analysisOptions);

        return [
            'search_run' => $searchRun->fresh('sources') ?? $searchRun,
            'analysis_run' => $analysisRun->fresh('sources') ?? $analysisRun,
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function createRun(array $attributes): AiVisibilityRun
    {
        return AiVisibilityRun::query()->create(array_replace([
            'status' => AiVisibilityRun::STATUS_RUNNING,
            'started_at' => now(),
        ], $attributes));
    }

    private function completeRun(
        AiVisibilityRun $run,
        AiVisibilityResult $result,
        ?SystemAiIdentity $identity = null,
        ?AiVisibilityModelExecutionSnapshot $snapshot = null,
        ?AiUsageReservation $modelReservation = null,
        ?AiUsageReservation $providerReservation = null,
    ): AiVisibilityRun {
        return DB::transaction(function () use ($run, $result, $identity, $snapshot, $modelReservation, $providerReservation): AiVisibilityRun {
            if ($identity instanceof SystemAiIdentity && $snapshot instanceof AiVisibilityModelExecutionSnapshot) {
                $this->executionGuard->assertCurrent($identity, $snapshot, lockForUpdate: true);
            }
            $lockedRun = AiVisibilityRun::query()->whereKey((int) $run->id)->lockForUpdate()->first();
            if (! $lockedRun instanceof AiVisibilityRun
                || (string) $lockedRun->status !== AiVisibilityRun::STATUS_RUNNING) {
                throw new AiVisibilityRunDiscardedException('ai_result_discarded');
            }
            $attributes = [
                'provider_key' => $result->providerKey ?? $run->provider_key,
                'model_id' => $result->modelId ?? $run->model_id,
                'status' => AiVisibilityRun::STATUS_COMPLETED,
                'answer_text' => $result->answerText !== '' ? $result->answerText : null,
                'latency_ms' => $result->latencyMs,
                'usage_json' => $result->usage !== [] ? $result->usage : null,
                'analysis_json' => $result->metadata !== [] ? $result->metadata : null,
                'raw_request_json' => $result->rawRequest !== [] ? $result->rawRequest : null,
                'raw_response_json' => $result->rawResponse !== [] ? $result->rawResponse : null,
                'error_message' => null,
                'completed_at' => now(),
            ];
            $lockedRun->update($attributes);

            AiVisibilitySource::query()->where('ai_visibility_run_id', (int) $run->id)->delete();
            foreach ($result->sources as $source) {
                AiVisibilitySource::query()->create(array_replace(
                    ['ai_visibility_run_id' => (int) $run->id],
                    $source->toDatabaseAttributes(),
                ));
            }

            if ($modelReservation !== null) {
                $this->usageQuota->recordModelSuccess($modelReservation);
            }
            if ($providerReservation !== null) {
                $this->usageQuota->recordProviderSuccess($providerReservation);
            }

            $run->setRawAttributes($lockedRun->getAttributes(), true);

            return $run;
        });
    }

    /**
     * @param  Closure(AiModel):AiVisibilityPreparedModelRequest  $requestPayload
     * @param  Closure(AiModel,AiVisibilityPreparedModelRequest):AiVisibilityResult  $provider
     */
    private function runModelCall(
        SystemAiIdentity $identity,
        AiVisibilityRun $run,
        AiModel $model,
        string $bindingType,
        string $callKeyPrefix,
        string $operation,
        int $maxProviderAttempts,
        Closure $requestPayload,
        Closure $provider,
    ): AiVisibilityRun {
        $snapshot = null;
        $maxProviderAttempts = max(1, $maxProviderAttempts);
        for ($providerOrdinal = 1; $providerOrdinal <= $maxProviderAttempts; $providerOrdinal++) {
            $lock = null;
            $reservation = null;
            $providerCalled = false;
            try {
                $lock = $this->invocationLocks->acquireForInvocation((int) $model->id, 300);
                $snapshot ??= $this->executionGuard->snapshotForRun($identity, $run, $model, $bindingType);
                $current = $this->executionGuard->assertCurrent($identity, $snapshot);
                $reservation = $this->usageQuota->reserveModel($current);
                if (! $reservation instanceof AiUsageReservation) {
                    throw new RuntimeException('ai_model_quota_exhausted');
                }
                $current = $this->executionGuard->assertCurrent($identity, $snapshot);
                $preparedRequest = $requestPayload($current);
                $attempt = $this->usageAttempts->beginForVisibilityCollection(
                    model: $current,
                    identity: $identity,
                    requestId: (string) $run->uuid,
                    requestPayload: $preparedRequest->digestPayload,
                    callKey: sprintf('%s.p%d', $callKeyPrefix, $providerOrdinal),
                    operation: $operation,
                    businessSource: 'ai_visibility_collection',
                    sourceType: AiVisibilityRun::class,
                    sourceId: (int) $run->id,
                );
                $current = $this->executionGuard->assertCurrent($identity, $snapshot);
                $providerCalled = true;
                try {
                    $result = $provider($current, $preparedRequest);
                } catch (Throwable $exception) {
                    $this->recordModelAttempt($reservation);
                    $attempt->failed($this->providerErrorCode($exception));
                    if ($providerOrdinal < $maxProviderAttempts && $this->isTransientProviderFailure($exception)) {
                        continue;
                    }

                    throw new RuntimeException($this->providerErrorCode($exception));
                }

                try {
                    $completed = $this->completeRun(
                        $run,
                        $result,
                        identity: $identity,
                        snapshot: $snapshot,
                        modelReservation: $reservation,
                    );
                } catch (AiVisibilityModelAccessRevokedException $exception) {
                    $this->recordModelAttempt($reservation);
                    $attempt->revoked('ai_config_access_revoked', $result->usage);
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->recordModelAttempt($reservation);
                    $attempt->discarded('ai_result_discarded', $result->usage);
                    throw new AiVisibilityRunDiscardedException('ai_result_discarded');
                }

                $attempt->succeeded($result->usage);

                return $completed;
            } catch (Throwable $exception) {
                if ($reservation instanceof AiUsageReservation && ! $providerCalled) {
                    $this->usageQuota->releaseModel($reservation);
                }
                $errorCode = $this->safeErrorCode($exception);
                $this->failRun($run, $errorCode);

                throw new RuntimeException($errorCode);
            } finally {
                $this->invocationLocks->release($lock);
            }
        }

        throw new RuntimeException('ai_provider_request_failed');
    }

    private function isTransientProviderFailure(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return preg_match('/HTTP\s*(?:429|5\d\d)\b/i', $message) === 1
            || preg_match('/(?:connection|connect|timed?\s*out|cURL\s+error)/i', $message) === 1;
    }

    private function failRun(AiVisibilityRun $run, Throwable|string $failure): void
    {
        $errorCode = is_string($failure) ? $failure : $this->safeErrorCode($failure);
        AiVisibilityRun::query()
            ->whereKey((int) $run->id)
            ->where('status', AiVisibilityRun::STATUS_RUNNING)
            ->update([
                'status' => AiVisibilityRun::STATUS_FAILED,
                'error_message' => $errorCode,
                'completed_at' => now(),
            ]);
        $run->forceFill([
            'status' => AiVisibilityRun::STATUS_FAILED,
            'error_message' => $errorCode,
            'completed_at' => now(),
        ]);
    }

    private function recordModelAttempt(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelAttempt($reservation);
        } catch (Throwable) {
            // Quota settlement must not replace the stable collection result.
        }
    }

    private function providerErrorCode(Throwable $exception): string
    {
        if ($exception->getMessage() === 'ai_competitor_response_invalid') {
            return 'ai_competitor_response_invalid';
        }

        return preg_match('/(?:HTTP\s*)?(?:401|403)\b/i', $exception->getMessage()) === 1
            ? 'ai_provider_auth_failed'
            : 'ai_provider_request_failed';
    }

    private function safeErrorCode(Throwable $exception): string
    {
        if ($exception instanceof AiVisibilityModelAccessRevokedException) {
            return 'ai_config_access_revoked';
        }
        if ($exception instanceof AiVisibilityRunDiscardedException) {
            return 'ai_result_discarded';
        }
        if ($exception instanceof ValidationException) {
            return 'ai_config_access_revoked';
        }
        if ($exception instanceof AiWorkspaceModelUnavailableException) {
            return 'ai_model_unavailable';
        }
        $message = trim($exception->getMessage());

        return preg_match('/\A[a-z0-9_.:-]{1,100}\z/', $message) === 1
            ? $message
            : $this->providerErrorCode($exception);
    }

    private function assertSourceProviderEnabled(AiSourceProvider $provider, string $label): void
    {
        if (($provider->status ?? 'inactive') !== 'active'
            || ! $this->endpointPolicy->acceptsSearchApi((string) ($provider->endpoint_url ?? ''))) {
            throw new RuntimeException($label.'不可用或已停用');
        }
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new RuntimeException('AI 可见性关键词为空');
        }

        return $keyword;
    }

    private function resolvePrompt(string $keyword, ?string $prompt): string
    {
        $prompt = trim((string) $prompt);

        return $prompt !== '' ? $prompt : sprintf(
            '请基于公开网络信息回答目标关键词「%s」相关问题，并在可用时保留引用来源。请重点说明主流 AI 可能会如何理解这个关键词。',
            $keyword,
        );
    }

    private function defaultAnalysisPrompt(string $keyword): string
    {
        return sprintf(
            "请分析目标关键词「%s」的 AI 可见性结果：\n1. 哪些信源最可能影响 AI 回答；\n2. 信源覆盖有哪些缺口；\n3. 建议优先投放/建设哪些页面、资料或内容；\n4. 给出可直接用于内容生成的要点。",
            trim($keyword),
        );
    }

    /**
     * @return list<AiVisibilitySourceData>
     */
    private function sourceDataFromRun(AiVisibilityRun $run): array
    {
        return $run->sources->map(static fn (AiVisibilitySource $source): AiVisibilitySourceData => new AiVisibilitySourceData(
            sourceType: (string) $source->source_type,
            citationKey: $source->citation_key,
            title: $source->title,
            url: $source->url,
            domain: $source->domain,
            siteName: $source->site_name,
            snippet: $source->snippet,
            summary: $source->summary,
            contentExcerpt: $source->content_excerpt,
            publishedAt: $source->published_at,
            rank: $source->rank,
            rankScore: $source->rank_score,
            authorityLevel: $source->authority_level,
            metadata: is_array($source->metadata_json) ? $source->metadata_json : [],
        ))->values()->all();
    }
}
