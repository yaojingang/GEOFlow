<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleAiQualityRollout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'epoch' => 1,
            'principle_percent' => 0,
            'execution_percent' => 0,
            'scoring_percent' => 0,
            'shadow_percent' => 0,
            'atomic_shadow_percent' => 0,
            'atomic_fact_percent' => 0,
            'atomic_fact_frozen' => false,
            'sampled_auto_release_enabled' => true,
            'frozen' => false,
        ]);
        $this->forget();

        return $rollout;
    }

    public function acquireDistributionLeaseEpoch(): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Distribution rollout leases require an active database transaction.');
        }

        $rollout = ArticleAiQualityRollout::query()
            ->whereKey(1)
            ->sharedLock()
            ->first();
        if (! $rollout instanceof ArticleAiQualityRollout) {
            ArticleAiQualityRollout::query()->firstOrCreate(['id' => 1], [
                'epoch' => 1,
                'principle_percent' => 0,
                'execution_percent' => 0,
                'scoring_percent' => 0,
                'shadow_percent' => 0,
                'atomic_shadow_percent' => 0,
                'atomic_fact_percent' => 0,
                'atomic_fact_frozen' => false,
                'sampled_auto_release_enabled' => true,
                'frozen' => false,
            ]);
            $rollout = ArticleAiQualityRollout::query()
                ->whereKey(1)
                ->sharedLock()
                ->firstOrFail();
        }

        return max(1, (int) $rollout->epoch);
    }

    public function sampledAutoReleaseEnabled(): bool
    {
        $state = $this->state();

        return (bool) config('geoflow.ai_quality_sampled_auto_release_enabled', true)
            && ! (bool) ($state['frozen'] ?? true)
            && (bool) ($state['sampled_auto_release_enabled'] ?? false);
    }

    public function atomicFactEnabled(int $articleId): bool
    {
        $state = $this->state();

        return ! (bool) ($state['frozen'] ?? true)
            && ! (bool) ($state['atomic_fact_frozen'] ?? true)
            && $this->inBucket($articleId, (int) ($state['atomic_fact_percent'] ?? 0));
    }

    public function atomicShadowEnabled(int $articleId): bool
    {
        $state = $this->state();

        return ! (bool) ($state['frozen'] ?? true)
            && ! (bool) ($state['atomic_fact_frozen'] ?? true)
            && $this->inBucket($articleId, (int) ($state['atomic_shadow_percent'] ?? 0));
    }

    public function atomicBucket(int $articleId): int
    {
        return crc32('atomic-fact:'.$articleId) % 100;
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
            'epoch' => max(1, (int) $rollout->epoch),
            'principle_percent' => $this->stage((int) $rollout->principle_percent),
            'execution_percent' => $this->stage((int) $rollout->execution_percent),
            'scoring_percent' => $this->stage((int) $rollout->scoring_percent),
            'shadow_percent' => $this->stage((int) $rollout->shadow_percent),
            'atomic_shadow_percent' => $this->stage((int) $rollout->atomic_shadow_percent),
            'atomic_fact_percent' => $this->stage((int) $rollout->atomic_fact_percent),
            'atomic_fact_frozen' => (bool) $rollout->atomic_fact_frozen,
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
            'epoch' => 1,
            'principle_percent' => $this->stage((int) config('geoflow.ai_quality_principle_v2_percent', 0)),
            'execution_percent' => $this->stage((int) config('geoflow.ai_quality_fast_v2_percent', 0)),
            'scoring_percent' => $this->stage((int) config('geoflow.ai_quality_scoring_v2_percent', 0)),
            'shadow_percent' => $this->stage((int) config('geoflow.ai_quality_shadow_v2_percent', 0)),
            'atomic_shadow_percent' => 0,
            'atomic_fact_percent' => 0,
            'atomic_fact_frozen' => false,
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
            'epoch' => 1,
            'principle_percent' => 0,
            'execution_percent' => 0,
            'scoring_percent' => 0,
            'shadow_percent' => 0,
            'atomic_shadow_percent' => 0,
            'atomic_fact_percent' => 0,
            'atomic_fact_frozen' => false,
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

    private function inBucket(int $articleId, int $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }

        return $percent >= 100 || $this->atomicBucket($articleId) < $percent;
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
