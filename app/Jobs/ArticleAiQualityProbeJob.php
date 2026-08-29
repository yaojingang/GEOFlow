<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ArticleAiQualityProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly string $token,
        public readonly string $expectedQueue,
    ) {}

    public function handle(): void
    {
        $actualQueue = $this->job?->getQueue() ?: $this->expectedQueue;
        Cache::put(self::cacheKey($this->token), [
            'queue' => $actualQueue,
            'connection' => $this->connection ?: 'redis',
            'acknowledged_at' => now()->toIso8601String(),
        ], now()->addMinute());
    }

    public static function cacheKey(string $token): string
    {
        return 'geoflow:ai-quality:probe:'.hash('sha256', $token);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality', 'ai-quality-probe'];
    }
}
