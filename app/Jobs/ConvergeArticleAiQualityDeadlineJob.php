<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 在单条质检的主截止或最终截止到达时收口。
 *
 * 未到时间、质检已结束时直接返回，方便同步队列立刻执行也不会误伤刚创建的记录。
 */
class ConvergeArticleAiQualityDeadlineJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 1200;

    public readonly ?string $expectedScope;

    public readonly ?int $expectedAt;

    public function __construct(
        public readonly int $checkId,
        public readonly string $phase,
        ?string $expectedScope = null,
        ?int $expectedAt = null,
    ) {
        $this->expectedScope = $expectedScope;
        $this->expectedAt = $expectedAt;
        $this->onConnection('redis')->onQueue('ai-quality-deadlines');
    }

    public function uniqueId(): string
    {
        $legacyKey = $this->checkId.':'.$this->phase;

        return isset($this->expectedScope, $this->expectedAt)
            ? $legacyKey.':'.$this->expectedScope.':'.$this->expectedAt
            : $legacyKey;
    }

    public function handle(ArticleAiQualityReconciliationService $reconciliation): void
    {
        if (isset($this->expectedAt)) {
            Log::debug('article_ai_quality_deadline.started', [
                'phase' => $this->phase,
                'scope' => $this->expectedScope ?? null,
                'lag_ms' => max(0, (int) round(((float) now()->format('U.u') - $this->expectedAt) * 1000)),
            ]);
        }

        $reconciliation->convergeCheck($this->checkId, $this->phase, $this->expectedScope ?? null, $this->expectedAt ?? null);
    }
}
