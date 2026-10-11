<?php

namespace App\Services\Site;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\LinkPool\LinkPoolTargetService;
use App\Support\Analytics\TrafficClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LinkPoolMetricsService
{
    public function __construct(
        private readonly LinkPoolSiteService $sites,
        private readonly DistributionChannelOperationLeaseService $leases,
    ) {}

    /** @param list<int> $placementIds */
    public function recordHtmlServed(array $placementIds, string $deliveryId): void
    {
        $placements = DB::table('link_placements')->whereIn('id', array_unique($placementIds))
            ->get(['source_site_key', 'target_site_key']);
        if ($placements->isEmpty()) {
            return;
        }
        $siteKeys = $placements->flatMap(static fn (object $placement): array => [
            $placement->source_site_key, $placement->target_site_key,
        ])->unique()->all();
        $this->withHostedLeases($siteKeys, 'link_pool_html_served', function () use ($placementIds, $deliveryId): void {
            DB::transaction(function () use ($placementIds, $deliveryId): void {
                if (DB::table('link_pool_html_deliveries')->insertOrIgnore([
                    'id' => $deliveryId, 'created_at' => now(),
                ]) !== 1) {
                    return;
                }
                foreach (DB::table('link_placements')->whereIn('id', array_unique($placementIds))->get() as $placement) {
                    $this->increment($placement, 'html_served');
                }
            }, 3);
        });
    }

    public function recordEvent(Request $request, int $placementId, string $eventUuid, string $type, string $siteKey): bool
    {
        if (! in_array($type, ['viewable', 'click'], true)
            || TrafficClassifier::classify($request->userAgent()) !== TrafficClassifier::HUMAN) {
            return false;
        }
        $targetSiteKey = DB::table('link_placements')->where('id', $placementId)
            ->where('source_site_key', $siteKey)->value('target_site_key');
        if ($targetSiteKey === null) {
            return false;
        }

        return (bool) $this->withHostedLeases([$siteKey, (string) $targetSiteKey], 'link_pool_event',
            fn (): bool => $this->recordCurrentEvent($request, $placementId, $eventUuid, $type, $siteKey));
    }

    private function recordCurrentEvent(Request $request, int $placementId, string $eventUuid, string $type, string $siteKey): bool
    {
        $placement = DB::table('link_placements as placements')
            ->join('link_selection_heads as heads', function ($join): void {
                $join->on('heads.batch_id', '=', 'placements.batch_id')
                    ->on('heads.source_site_key', '=', 'placements.source_site_key')
                    ->on('heads.source_article_id', '=', 'placements.source_article_id');
            })
            ->where('placements.id', $placementId)
            ->where('placements.source_site_key', $siteKey)
            ->select('placements.*')->first();
        $target = $placement ? app(LinkPoolTargetService::class)->forPlacement($placement) : null;
        if ($placement === null || ! $this->sites->canPublish($siteKey)
            || $this->sites->article($siteKey, (int) $placement->source_article_id) === null
            || $target === null || ! app(LinkPoolTargetService::class)->eligible($target)
            || ! DB::table('link_campaigns')->where('id', $placement->campaign_id)->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists()
            || ! LinkPoolTargetService::isExternal($target) && $placement->source_site_key !== $placement->target_site_key
                && ! DB::table('link_campaign_targets as relations')
                    ->join('link_pool_targets as targets', 'targets.id', '=', 'relations.target_id')
                    ->where('relations.campaign_id', $placement->campaign_id)
                    ->where('relations.cross_site_review_status', 'approved')
                    ->where('targets.target_site_key', $placement->target_site_key)
                    ->where('targets.article_id', $placement->target_article_id)->exists()) {
            return false;
        }
        $dedupe = 'link-pool-event:'.hash('sha256', implode('|', [
            $siteKey, $request->session()->getId(), $placementId, $type,
        ]));
        if (! Cache::add($dedupe, true, 30)) {
            return true;
        }
        $inserted = DB::table('link_pool_events')->insertOrIgnore([
            'event_uuid' => $eventUuid,
            'event_type' => $type,
            'batch_id' => $placement->batch_id,
            'placement_id' => $placementId,
            'source_site_key' => $placement->source_site_key,
            'target_site_key' => $placement->target_site_key,
            'source_article_id' => $placement->source_article_id,
            'target_article_id' => $placement->target_article_id,
            'target_id' => $target->id,
            'campaign_id' => $placement->campaign_id,
            'created_at' => now(),
        ]);
        if ($inserted === 1) {
            $this->increment($placement, $type === 'viewable' ? 'viewable' : 'human_clicks');
        }

        return true;
    }

    /** @param list<string> $siteKeys */
    private function withHostedLeases(array $siteKeys, string $operation, \Closure $callback): mixed
    {
        $channels = [];
        foreach (array_unique($siteKeys) as $siteKey) {
            if (in_array($siteKey, ['primary', 'external'], true)) {
                continue;
            }
            $channel = $this->sites->profile($siteKey)?->channel;
            if ($channel === null) {
                return false;
            }
            $channels[(int) $channel->id] = $channel;
        }
        ksort($channels);
        $run = $callback;
        foreach (array_reverse($channels, true) as $channel) {
            $next = $run;
            $run = fn () => $this->leases->run($channel, $operation, $next);
        }
        try {
            return $run();
        } catch (DistributionChannelDeletionBlocked) {
            return false;
        }
    }

    private function increment(object $placement, string $column): void
    {
        $date = now()->toDateString();
        $data = [
            'date' => $date,
            'source_site_key' => (string) $placement->source_site_key,
            'target_site_key' => (string) $placement->target_site_key,
            'campaign_id' => (int) $placement->campaign_id,
            'source_article_id' => (int) $placement->source_article_id,
            'target_article_id' => $placement->target_article_id === null ? null : (int) $placement->target_article_id,
            'batch_id' => (int) $placement->batch_id,
        ];
        if ($placement->target_site_key === 'external') {
            $data['target_id'] = (int) $placement->target_id;
        }
        $key = hash('sha256', json_encode($data));
        DB::table('link_metrics_daily')->insertOrIgnore([
            'metric_key' => $key, ...$data, 'target_id' => $placement->target_id,
            'html_served' => 0, 'viewable' => 0, 'human_clicks' => 0,
        ]);
        DB::table('link_metrics_daily')->where('metric_key', $key)->increment($column);
    }
}
