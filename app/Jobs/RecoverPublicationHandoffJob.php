<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticlePublicationDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 在发布交接的 next_at 到达时重试这一条交接。
 */
class RecoverPublicationHandoffJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    /**
     * @param  int  $receiptId  distribution_logs 中的交接回执 ID
     */
    public readonly ?string $expectedRetryAt;

    public function __construct(public readonly int $receiptId, ?string $expectedRetryAt = null)
    {
        $this->expectedRetryAt = $expectedRetryAt;
        $this->onQueue('distribution');
    }

    /**
     * 同一条交接回执只保留一个补偿任务。
     */
    public function uniqueId(): string
    {
        return isset($this->expectedRetryAt) ? $this->receiptId.':'.$this->expectedRetryAt : (string) $this->receiptId;
    }

    /**
     * 未到 next_at 时由交接服务自己跳过。
     */
    public function handle(ArticlePublicationDeliveryService $deliveries): void
    {
        $deliveries->attempt($this->receiptId, $this->expectedRetryAt ?? null);
    }
}
