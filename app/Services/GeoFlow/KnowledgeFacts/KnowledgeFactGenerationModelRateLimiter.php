<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Exceptions\KnowledgeFactModelRateLimitExceeded;
use App\Models\AiModel;
use Illuminate\Support\Facades\RateLimiter;

final class KnowledgeFactGenerationModelRateLimiter
{
    private const DECAY_SECONDS = 60;

    public function reserve(AiModel $model): void
    {
        $key = $this->key((int) $model->getKey());
        $attempts = RateLimiter::increment($key, self::DECAY_SECONDS);
        $maximumAttempts = max(
            1,
            (int) config('geoflow.knowledge_fact_generation_rate_per_minute', 10),
        );

        if ($attempts <= $maximumAttempts) {
            return;
        }

        RateLimiter::decrement($key, self::DECAY_SECONDS);

        throw new KnowledgeFactModelRateLimitExceeded(
            max(1, RateLimiter::availableIn($key)),
        );
    }

    public function key(int $modelId): string
    {
        return 'knowledge-fact-generation:model:'.$modelId;
    }
}
