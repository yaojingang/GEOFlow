<?php

namespace App\Jobs;

use App\Services\LinkPool\LinkPoolUrlHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CheckLinkPoolTargetUrl implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $targetId, public readonly string $urlHash) {}

    public function uniqueId(): string
    {
        return $this->targetId.':'.$this->urlHash;
    }

    public function handle(LinkPoolUrlHealthService $health): void
    {
        $health->check($this->targetId, $this->urlHash);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('link_pool_targets')->where('id', $this->targetId)->where('url_sha256', $this->urlHash)->update([
            'health_status' => 'unchecked', 'health_message' => '检查进程中断，请重新检查。', 'health_checked_at' => now(),
        ]);
    }
}
