<?php

namespace Tests\Support;

use App\Jobs\BuildSitemapManifest;
use App\Jobs\CheckUrlChange;
use App\Jobs\RefreshUrlChange;
use App\Models\UrlChangeRequest;
use App\Services\Site\UrlChangeService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

trait ChecksUrlChanges
{
    protected function prepareUrlQueue(): void
    {
        Queue::fake([CheckUrlChange::class, RefreshUrlChange::class, BuildSitemapManifest::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
    }

    protected function checkedChange(string $status = 'ready'): UrlChangeRequest
    {
        $change = UrlChangeRequest::query()->latest('created_at')->latest('id')->firstOrFail();
        for ($i = 0; $i < 30 && $change->refresh()->status === 'checking'; $i++) {
            app(UrlChangeService::class)->checkSegment($change);
        }
        $this->assertSame($status, $change->refresh()->status, (string) $change->error);

        return $change;
    }
}
