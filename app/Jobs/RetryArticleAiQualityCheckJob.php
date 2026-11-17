<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 在技术重试的 next_at 到达时，只重试这一条失败质检。
 */
class RetryArticleAiQualityCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    /**
     * @param  int  $checkId  失败质检记录 ID
     */
    public function __construct(public readonly int $checkId)
    {
        $this->onConnection('redis')->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'));
    }

    /**
     * 同一条失败记录只保留一个重试任务。
     */
    public function uniqueId(): string
    {
        return (string) $this->checkId;
    }

    public function handle(ArticleAiQualityReconciliationService $reconciliation): void
    {
        $reconciliation->retryFailedCheck($this->checkId);
    }
}
