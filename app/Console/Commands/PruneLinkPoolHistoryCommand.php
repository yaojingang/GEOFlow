<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneLinkPoolHistoryCommand extends Command
{
    protected $signature = 'geoflow:prune-link-pool {--limit=5000}';

    protected $description = 'Prune link recommendation events, metrics, and non-current batches after 90 days';

    public function handle(): int
    {
        if (! Schema::hasTable('link_selection_batches')) {
            $this->info('Link pool has not been installed.');

            return self::SUCCESS;
        }

        $limit = max(1, min(10000, (int) $this->option('limit')));
        $cutoff = now()->subDays(90);
        $eventIds = DB::table('link_pool_events')->where('created_at', '<', $cutoff)
            ->orderBy('id')->limit($limit)->pluck('id')->all();
        $metricIds = DB::table('link_metrics_daily')->where('date', '<', $cutoff->toDateString())
            ->orderBy('id')->limit($limit)->pluck('id')->all();
        $batchIds = DB::table('link_selection_batches')->where('generated_at', '<', $cutoff)
            ->whereNotIn('id', DB::table('link_selection_heads')->select('batch_id'))
            ->orderBy('id')->limit($limit)->pluck('id')->all();
        $importBatchIds = DB::table('link_pool_import_batches')->where('created_at', '<', $cutoff)
            ->orderBy('created_at')->limit($limit)->pluck('id')->all();
        $deliveryIds = DB::table('link_pool_html_deliveries')->where('created_at', '<', $cutoff)
            ->orderBy('created_at')->limit($limit)->pluck('id')->all();

        DB::transaction(static function () use ($eventIds, $metricIds, $batchIds, $importBatchIds, $deliveryIds): void {
            DB::table('link_pool_events')->whereIn('id', $eventIds)->delete();
            DB::table('link_metrics_daily')->whereIn('id', $metricIds)->delete();
            DB::table('link_placements')->whereIn('batch_id', $batchIds)->delete();
            DB::table('link_selection_batches')->whereIn('id', $batchIds)->delete();
            DB::table('link_pool_import_batches')->whereIn('id', $importBatchIds)->delete();
            DB::table('link_pool_html_deliveries')->whereIn('id', $deliveryIds)->delete();
        }, 3);

        $this->info(sprintf('Pruned link pool history: %d events, %d metric rows, %d batches, %d import batches, %d deliveries.',
            count($eventIds), count($metricIds), count($batchIds), count($importBatchIds), count($deliveryIds)));

        return self::SUCCESS;
    }
}
