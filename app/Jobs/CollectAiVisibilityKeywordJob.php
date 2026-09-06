<?php

namespace App\Jobs;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CollectAiVisibilityKeywordJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1200;

    public function __construct(public readonly string $keyword) {}

    public function uniqueId(): string
    {
        return hash('sha256', $this->keyword);
    }

    public function handle(AiVisibilityCollectionService $collection): void
    {
        $runs = $collection->collect(SystemAiIdentity::forVisibilityCollection(), $this->keyword);
        foreach ($runs as $run) {
            if ($run instanceof AiVisibilityRun && trim((string) $run->answer_text) !== '') {
                DetectAiVisibilityCompetitorsJob::dispatch((int) $run->id);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ai_visibility_keyword_job_failed', ['keyword_hash' => $this->uniqueId(), 'exception_type' => $exception ? $exception::class : null]);
    }
}
