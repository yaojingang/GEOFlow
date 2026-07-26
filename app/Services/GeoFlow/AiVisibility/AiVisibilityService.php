<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiUsageReservation;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function runDoubaoArkResponses(AiModel $model, string $keyword, ?string $prompt = null, array $options = []): AiVisibilityRun
    {
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

        $reservation = null;
        try {
            $this->assertAiModelEnabled($model, 'ark', '豆包 Ark 模型');
            $reservation = $this->usageQuota->reserveModel($model);
            if ($reservation === null) {
                throw new RuntimeException('豆包 Ark 模型已达到每日调用上限');
            }
            $result = $this->doubaoArkResponsesClient->answerWithWebSearch($model, $prompt, $options);

            return $this->completeRun($run, $result, modelReservation: $reservation);
        } catch (Throwable $exception) {
            if ($reservation !== null) {
                $this->usageQuota->releaseModel($reservation);
            }
            $this->failRun($run, $exception);
            throw $exception;
        }
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
                throw new RuntimeException('豆包 Search Custom 信源供应商已达到每日调用上限');
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
            $this->failRun($run, $exception);
            throw $exception;
        }
    }

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @param  array<string,mixed>  $options
     */
    public function runDeepSeekAnalysis(AiModel $model, string $keyword, string $prompt, array $sources = [], array $options = []): AiVisibilityRun
    {
        $keyword = $this->normalizeKeyword($keyword);
        $run = $this->createRun([
            'keyword' => $keyword,
            'prompt' => $prompt,
            'provider_type' => AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
            'provider_key' => 'deepseek',
            'ai_model_id' => (int) $model->id,
            'model_id' => (string) ($model->model_id ?? ''),
            'locale' => (string) ($options['locale'] ?? 'zh_CN'),
        ]);

        $reservation = null;
        try {
            $this->assertAiModelEnabled($model, 'deepseek', 'DeepSeek 分析模型');
            $reservation = $this->usageQuota->reserveModel($model);
            if ($reservation === null) {
                throw new RuntimeException('DeepSeek 分析模型已达到每日调用上限');
            }
            $result = $this->deepSeekAnalysisClient->analyze($model, $prompt, $sources, $options);

            return $this->completeRun($run, $result, modelReservation: $reservation);
        } catch (Throwable $exception) {
            if ($reservation !== null) {
                $this->usageQuota->releaseModel($reservation);
            }
            $this->failRun($run, $exception);
            throw $exception;
        }
    }

    /**
     * @param  array<string,mixed>  $searchOptions
     * @param  array<string,mixed>  $analysisOptions
     * @return array{search_run:AiVisibilityRun,analysis_run:AiVisibilityRun}
     */
    public function runDoubaoSearchThenDeepSeekAnalysis(
        AiSourceProvider $sourceProvider,
        AiModel $analysisModel,
        string $keyword,
        ?string $analysisPrompt = null,
        array $searchOptions = [],
        array $analysisOptions = [],
    ): array {
        $searchRun = $this->runDoubaoSearchCustom($sourceProvider, $keyword, $searchOptions);
        $searchRun->load('sources');
        $sources = $this->sourceDataFromRun($searchRun);
        $prompt = $analysisPrompt !== null && trim($analysisPrompt) !== ''
            ? $analysisPrompt
            : $this->defaultAnalysisPrompt($keyword);

        $analysisRun = $this->runDeepSeekAnalysis($analysisModel, $keyword, $prompt, $sources, $analysisOptions);

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
        ?AiUsageReservation $modelReservation = null,
        ?AiUsageReservation $providerReservation = null,
    ): AiVisibilityRun {
        return DB::transaction(function () use ($run, $result, $modelReservation, $providerReservation): AiVisibilityRun {
            $run->update([
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
            ]);

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

            return $run->fresh('sources') ?? $run;
        });
    }

    private function failRun(AiVisibilityRun $run, Throwable $exception): void
    {
        $run->update([
            'status' => AiVisibilityRun::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 4000, 'UTF-8'),
            'completed_at' => now(),
        ]);
    }

    private function assertAiModelEnabled(AiModel $model, string $bindingType, string $label): void
    {
        $modelType = trim((string) ($model->model_type ?? ''));
        if (($model->status ?? 'inactive') !== 'active'
            || ($modelType !== '' && $modelType !== 'chat')
            || ! $this->endpointPolicy->acceptsModelApi($bindingType, (string) ($model->api_url ?? ''))) {
            throw new RuntimeException($label.'不可用或已停用');
        }
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
