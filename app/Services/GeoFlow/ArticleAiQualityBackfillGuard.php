<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\ArticleAiQualityCheck;
use Illuminate\Support\Facades\Cache;

final readonly class ArticleAiQualityBackfillGuard
{
    public function __construct(private ArticleAiQualityProviderCircuitBreaker $circuitBreaker) {}

    public function pauseReason(?AiModel $model = null): ?string
    {
        $waitingTooLong = ArticleAiQualityCheck::query()
            ->where('status', 'queued')
            ->where('created_at', '<=', now()->subSeconds(
                max(1, (int) config('geoflow.ai_quality_front_queue_wait_seconds', 10)),
            ))
            ->where(function ($query): void {
                $query->whereNull('execution_meta->trigger')
                    ->orWhereNotIn('execution_meta->trigger', ['reconcile', 'backfill']);
            })
            ->exists();
        if ($waitingTooLong) {
            return 'front_queue_wait_exceeded';
        }
        if (! $model instanceof AiModel || (string) $model->status !== 'active') {
            return null;
        }
        if ($this->circuitBreaker->isOpen($model)) {
            return 'provider_circuit_open';
        }

        $dailyLimit = (int) ($model->daily_limit ?? 0);
        $usedToday = $model->usage_date?->isToday() ? (int) ($model->used_today ?? 0) : 0;
        $reserve = max(0, (int) config('geoflow.ai_quality_backfill_quota_reserve', 2));
        if ($dailyLimit > 0 && ($dailyLimit - $usedToday) <= $reserve) {
            return 'provider_quota_low';
        }

        return null;
    }

    public function preserveCursor(int $articleId): void
    {
        Cache::put($this->cursorKey(), max(0, $articleId), now()->addDay());
    }

    public function resumeCursor(): int
    {
        return max(0, (int) Cache::get($this->cursorKey(), 0));
    }

    public function clearCursor(): void
    {
        Cache::forget($this->cursorKey());
    }

    private function cursorKey(): string
    {
        return 'geoflow:ai-quality:backfill-cursor';
    }
}
