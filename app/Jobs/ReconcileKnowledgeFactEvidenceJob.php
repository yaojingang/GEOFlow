<?php

namespace App\Jobs;

use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEvidenceReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ReconcileKnowledgeFactEvidenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $knowledgeBaseId, public readonly string $sourceHash)
    {
        $this->onQueue('knowledge');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('knowledge-fact-evidence:'.$this->knowledgeBaseId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(KnowledgeFactEvidenceReconciler $reconciler): void
    {
        $reconciler->reconcile($this->knowledgeBaseId, $this->sourceHash);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeFactEvidenceReconciler::class)->markStale($this->knowledgeBaseId, 'reconcile_failed');
    }
}
