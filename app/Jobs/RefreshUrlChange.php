<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\UrlChangeRequest;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Services\Site\HostedSiteResolver;
use App\Services\Site\SitemapManifest;
use App\Services\Site\UrlChangeService;
use App\Services\Site\UrlChangeVersions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefreshUrlChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly string $changeId) {}

    public function handle(UrlChangeService $changes, HostedSiteUrlGenerator $urls, HostedSiteResolver $resolver): void
    {
        $lock = Cache::lock('url-change:'.$this->changeId, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $change = UrlChangeRequest::query()->find($this->changeId);
            if (! $change || ! in_array($change->status, ['applied', 'refreshing'], true)) {
                return;
            }
            $progress = $change->progress;
            $site = $change->sites[$progress['refresh_site']] ?? null;
            if ($site === null) {
                $changes->finish($change, 'completed');

                return;
            }
            if (isset($site['channel_id'])) {
                $currentChannel = DistributionChannel::query()->with('hostedSiteProfile')->find($site['channel_id']);
                if (! $currentChannel || $currentChannel->status === 'deleting' || ! $currentChannel->hostedSiteProfile || $currentChannel->hostedSiteProfile->serving_status === 'archived') {
                    $summary = $change->summary;
                    $summary['refresh_skipped'][$site['key']] = ['label' => $site['label'], 'reason' => __('url_change.errors.site_unavailable')];
                    $progress['refresh_site']++;
                    $progress['refresh_after'] = 0;
                    $progress['refresh_phase'] = 'sitemap';
                    $change->forceFill(['status' => 'refreshing', 'progress' => $progress, 'summary' => $summary])->save();
                    self::dispatch($change->id)->delay(now()->addSecond());

                    return;
                }
            }
            if (($progress['refresh_phase'] ?? 'sitemap') === 'sitemap') {
                $freshSite = $changes->sites(isset($site['channel_id']) ? 'hosted' : 'primary', $site['channel_id'] ?? null, false)[0];
                if (app(SitemapManifest::class)->buildStep($freshSite)) {
                    $progress['refresh_phase'] = 'records';
                }
                $change->forceFill(['status' => 'refreshing', 'progress' => $progress])->save();
                self::dispatch($change->id)->delay(now()->addSecond());

                return;
            }
            if (isset($site['channel_id'])) {
                DB::transaction(function () use ($change, $site, $urls, $resolver, &$progress): void {
                    $channel = DistributionChannel::query()->whereKey($site['channel_id'])->lockForUpdate()->with('hostedSiteProfile')->first();
                    if (! $channel || $channel->status === 'deleting') {
                        $progress['refresh_site']++;
                        $progress['refresh_after'] = 0;
                        $progress['refresh_phase'] = 'sitemap';

                        return;
                    }
                    $versions = app(UrlChangeVersions::class)->snapshot([$site['key']], true);
                    if (($progress['refresh_versions'][$site['key']] ?? null) !== $versions[$site['key']]) {
                        $progress['refresh_after'] = 0;
                        $progress['refresh_phase'] = 'sitemap';
                        $progress['refresh_versions'][$site['key']] = $versions[$site['key']];

                        return;
                    }
                    $query = ArticleDistribution::query()->where('distribution_channel_id', $channel->id)
                        ->where('status', 'synced')->where('action', '!=', 'delete')->where('id', '>', $progress['refresh_after']);
                    if ($change->operation === 'category') {
                        $query->whereHas('article', fn ($article) => $article->withTrashed()->where('category_id', $change->target_id));
                    } elseif ($change->operation === 'article_category') {
                        $query->where('article_id', $change->target_id);
                    }
                    $rows = $query->with([
                        'article' => fn ($article) => $article->withTrashed()->select(['id', 'slug', 'category_id', 'created_at']),
                        'article.category:id,slug',
                    ])->orderBy('id')->limit(200)->get();
                    foreach ($rows as $row) {
                        if ($row->article instanceof Article) {
                            $row->forceFill(['remote_url' => $urls->article($channel, $row->article)])->save();
                        }
                        $progress['refresh_after'] = (int) $row->id;
                    }
                    $profile = $channel->hostedSiteProfile;
                    if ($profile) {
                        DB::afterCommit(fn () => $resolver->invalidate($profile->hostname));
                    }
                    if ($rows->count() < 200) {
                        $progress['refresh_site']++;
                        $progress['refresh_after'] = 0;
                        $progress['refresh_phase'] = 'sitemap';
                    }
                }, 3);
            } else {
                $progress['refresh_site']++;
                $progress['refresh_phase'] = 'sitemap';
            }
            $change->forceFill(['status' => 'refreshing', 'progress' => $progress, 'error' => null])->save();
            self::dispatch($change->id)->delay(now()->addSecond());
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        UrlChangeRequest::query()->whereKey($this->changeId)->whereIn('status', ['applied', 'refreshing'])
            ->update(['error' => __('url_change.errors.refresh_failed')]);
    }
}
