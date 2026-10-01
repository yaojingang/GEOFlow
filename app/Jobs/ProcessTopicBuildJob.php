<?php

namespace App\Jobs;

use App\Models\TopicBuildRun;
use App\Services\Topics\TopicGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTopicBuildJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId, public readonly ?string $dispatchKey = null) {}

    public function handle(TopicGenerationService $generation): void
    {
        $generation->process($this->runId, $this->dispatchKey);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->dispatchKey === null) {
            return;
        }TopicBuildRun::query()->whereKey($this->runId)->where('dispatch_key', $this->dispatchKey)->whereIn('status', ['pending', 'running'])->where(fn ($q) => $q->where('status', 'pending')->orWhere('lease_expires_at', '<=', now()))->update(['status' => 'failed', 'phase' => 'finished', 'error' => '生成超时或执行中断，可以重试。', 'lease_token' => null, 'finished_at' => now()]);
    }
}
