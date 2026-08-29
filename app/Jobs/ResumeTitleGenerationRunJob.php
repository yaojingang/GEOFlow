<?php

namespace App\Jobs;

use App\Services\GeoFlow\TitleGenerationCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ResumeTitleGenerationRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 90000;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $runId,
        public readonly int $batchSequence,
    ) {}

    public function uniqueId(): string
    {
        return $this->runId.':'.$this->batchSequence;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'title-generation-resume',
            'title-generation-run:'.$this->runId,
        ];
    }

    public function handle(TitleGenerationCoordinator $coordinator): void
    {
        $coordinator->resumeDeferred($this->runId, $this->batchSequence);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(TitleGenerationCoordinator::class)->markResumeFailed(
            $this->runId,
            $this->batchSequence,
            $exception ?? new \RuntimeException('title_generation_resume_failed'),
        );
    }
}
