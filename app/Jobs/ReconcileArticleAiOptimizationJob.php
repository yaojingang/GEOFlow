<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticleAiOptimizationReconciliationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileArticleAiOptimizationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 240;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(public readonly int $limit = 500) {}

    public function uniqueId(): string
    {
        return 'article-ai-optimization-reconciliation';
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality-optimization', 'ai-quality-optimization-reconcile'];
    }

    public function handle(ArticleAiOptimizationReconciliationService $reconciliation): void
    {
        $reconciliation->reconcile($this->limit);
    }
}
