<?php

namespace App\Jobs;

use App\Exceptions\UrlChangeReportException;
use App\Models\UrlChangeRequest;
use App\Services\Site\UrlChangeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckUrlChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly string $changeId) {}

    public function handle(UrlChangeService $changes): void
    {
        $lock = Cache::lock('url-change:'.$this->changeId, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $change = UrlChangeRequest::query()->find($this->changeId);
            if ($change?->status !== 'checking') {
                return;
            }
            $changes->checkSegment($change);
            if ($change->refresh()->status === 'checking') {
                self::dispatch($change->id)->delay(now()->addSecond());
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $change = UrlChangeRequest::query()->find($this->changeId);
        if ($change?->status === 'checking') {
            app(UrlChangeService::class)->finish($change, 'failed', $exception instanceof UrlChangeReportException ? $exception->getMessage() : __('url_change.errors.check_failed'));
        }
    }
}
