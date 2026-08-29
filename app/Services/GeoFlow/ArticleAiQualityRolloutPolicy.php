<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleAiQualityRollout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ArticleAiQualityRolloutPolicy
{
    public const STAGES = [0, 10, 25, 50, 100];

    private const CACHE_KEY = 'geoflow.ai-quality.rollout.v1';

    /** @return array<string,mixed> */
    public function state(): array
    {
        if (! $this->tableExists()) {
            return $this->configurationFallback();
        }

        return Cache::remember(self::CACHE_KEY, now()->addSeconds(15), function (): array {
            $rollout = ArticleAiQualityRollout::query()->find(1);

            return $rollout ? $this->serialize($rollout) : $this->safeDefaults('database_uninitialized');
        });
    }

    public function ensureState(): ArticleAiQualityRollout
    {
        $rollout = ArticleAiQualityRollout::query()->firstOrCreate(['id' => 1], [
            'principle_percent' => 0,
            'execution_percent' => 0,
            'scoring_percent' => 0,
            'shadow_percent' => 0,
            'sampled_auto_release_enabled' => true,
            'frozen' => false,
        ]);
        $this->forget();

        return $rollout;
    }

    public function sampledAutoReleaseEnabled(): bool
    {
        $state = $this->state();

        return (bool) config('geoflow.ai_quality_sampled_auto_release_enabled', true)
            && ! (bool) ($state['frozen'] ?? true)
            && (bool) ($state['sampled_auto_release_enabled'] ?? false);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function validStage(int $stage): bool
    {
        return in_array($stage, self::STAGES, true);
    }

    /** @return array<string,mixed> */
    private function serialize(ArticleAiQualityRollout $rollout): array
    {
        return [
            'source' => 'database',
            'principle_percent' => $this->stage((int) $rollout->principle_percent),
            'execution_percent' => $this->stage((int) $rollout->execution_percent),
            'scoring_percent' => $this->stage((int) $rollout->scoring_percent),
            'shadow_percent' => $this->stage((int) $rollout->shadow_percent),
            'sampled_auto_release_enabled' => (bool) $rollout->sampled_auto_release_enabled,
            'frozen' => (bool) $rollout->frozen,
            'incident_code' => $rollout->incident_code,
            'latest_evaluation_path' => $rollout->latest_evaluation_path,
            'latest_evaluation_at' => $rollout->latest_evaluation_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function configurationFallback(): array
    {
        return [
            'source' => 'configuration_fallback',
            'principle_percent' => $this->stage((int) config('geoflow.ai_quality_principle_v2_percent', 0)),
            'execution_percent' => $this->stage((int) config('geoflow.ai_quality_fast_v2_percent', 0)),
            'scoring_percent' => $this->stage((int) config('geoflow.ai_quality_scoring_v2_percent', 0)),
            'shadow_percent' => $this->stage((int) config('geoflow.ai_quality_shadow_v2_percent', 0)),
            'sampled_auto_release_enabled' => true,
            'frozen' => false,
            'incident_code' => null,
            'latest_evaluation_path' => null,
            'latest_evaluation_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function safeDefaults(string $source): array
    {
        return [
            'source' => $source,
            'principle_percent' => 0,
            'execution_percent' => 0,
            'scoring_percent' => 0,
            'shadow_percent' => 0,
            'sampled_auto_release_enabled' => true,
            'frozen' => false,
            'incident_code' => null,
            'latest_evaluation_path' => null,
            'latest_evaluation_at' => null,
        ];
    }

    private function stage(int $value): int
    {
        return $this->validStage($value) ? $value : 0;
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('article_ai_quality_rollouts');
        } catch (Throwable) {
            return false;
        }
    }
}
