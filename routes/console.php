<?php

/**
 * Artisan 自定义命令注册（闭包命令或后续类命令）。
 */

use App\Services\GeoFlow\ArticleMarkdownExportService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'geoflow:recover-knowledge-syncs {--stale=600} {--limit=50}',
    function (KnowledgeChunkSyncCoordinator $coordinator): int {
        $recovered = $coordinator->recoverStale(
            max(60, (int) $this->option('stale')),
            max(1, min(200, (int) $this->option('limit'))),
        );
        $this->info(sprintf('Recovered stale knowledge syncs: %d', $recovered));

        return 0;
    }
)->purpose('Requeue knowledge chunk sync pipelines that stopped making progress');

Artisan::command('geoflow:prune-expired-cache {--limit=5000}', function (): int {
    $store = (string) config('cache.limiter', config('cache.default'));
    $storeConfig = (array) config('cache.stores.'.$store, []);
    if (($storeConfig['driver'] ?? null) !== 'database') {
        $this->info('Limiter cache does not use the database store.');

        return 0;
    }

    $connection = $storeConfig['connection'] ?? null;
    $table = (string) ($storeConfig['table'] ?? 'cache');
    $keys = DB::connection($connection)
        ->table($table)
        ->where('expiration', '<=', now()->getTimestamp())
        ->orderBy('expiration')
        ->limit(max(1, min(20000, (int) $this->option('limit'))))
        ->pluck('key');
    $deleted = $keys->isEmpty()
        ? 0
        : DB::connection($connection)->table($table)->whereIn('key', $keys->all())->delete();
    $this->info(sprintf('Pruned expired cache rows: %d', $deleted));

    return 0;
})->purpose('Delete expired database cache rows used by rate limiters');

Artisan::command('geoflow:prune-article-exports', function (ArticleMarkdownExportService $exports): int {
    $deleted = $exports->pruneExpired();
    $this->info(sprintf('Pruned article export artifacts: %d', $deleted));

    return 0;
})->purpose('Delete expired Markdown article export files');

/**
 * Horizon 监控快照：用于沉淀队列吞吐、等待等时序指标。
 */
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

/**
 * GeoFlow 任务调度：每分钟扫描一次可执行任务并入队（对齐 bak cron 逻辑）。
 */
Schedule::command('geoflow:schedule-tasks')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-knowledge-syncs')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-title-generations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:reconcile-ai-quality')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:reconcile-ai-optimization')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:converge-ai-quality')
    ->everyFiveSeconds()
    ->onOneServer()
    ->withoutOverlapping(1);

Schedule::command('geoflow:ai-quality-health --json')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(1);

Schedule::command('geoflow:prune-expired-cache')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:prune-article-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:prune-ai-workspace')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('geoflow:prune-task-trash')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('hosted-sites:reconcile', [
    '--limit' => (int) config('geoflow.hosted_sites.reconcile_limit', 500),
])
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);
