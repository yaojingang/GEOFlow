<?php

namespace App\Jobs;

use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 在分发队列提交失败后的重试时间到达时，重新投递这一条分发。
 */
class RetryDistributionQueueSubmissionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    /**
     * @param  int  $distributionId  文章分发记录 ID
     */
    public readonly ?string $expectedRetryAt;

    public function __construct(public readonly int $distributionId, ?string $expectedRetryAt = null)
    {
        $this->expectedRetryAt = $expectedRetryAt;
        $this->onQueue('distribution');
    }

    /**
     * 同一条分发只保留一个重新提交任务。
     */
    public function uniqueId(): string
    {
        return isset($this->expectedRetryAt) ? $this->distributionId.':'.$this->expectedRetryAt : (string) $this->distributionId;
    }

    /**
     * 已经提交成功，或重试时间还没到时直接返回。
     */
    public function handle(DistributionOrchestrator $orchestrator): void
    {
        $orchestrator->retryUndispatched($this->distributionId, $this->expectedRetryAt ?? null);
    }
}
