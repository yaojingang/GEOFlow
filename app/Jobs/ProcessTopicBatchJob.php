<?php

namespace App\Jobs;

use App\Services\Topics\TopicBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTopicBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $batchId, public readonly int $generation) {}

    public function handle(TopicBatchService $batches): void
    {
        $batches->processNext($this->batchId, $this->generation);
    }

    public function failed(?\Throwable $e): void
    {
        app(TopicBatchService::class)->failRunning($this->batchId, $this->generation);
    }
}
