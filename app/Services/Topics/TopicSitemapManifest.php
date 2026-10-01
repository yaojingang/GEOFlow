<?php

namespace App\Services\Topics;

use App\Jobs\BuildTopicSitemapManifest;
use App\Models\Topic;
use App\Models\TopicSourceInvalidation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Cache only ID boundaries. Every emitted URL still uses the current safe public view. */
final class TopicSitemapManifest
{
    public function limit(): int
    {
        return min(50000, max(2, (int) config('geoflow.hosted_sites.sitemap_url_limit', 50000)));
    }

    public function current(string $site): ?array
    {
        if (! Schema::hasTable('topics')) {
            return null;
        }
        $key = 'topics:sitemap:'.$site;
        $active = Cache::get($key);
        $version = $this->version($site);
        if (is_array($active) && $active['version'] === $version) {
            return $active;
        }
        $count = Topic::query()->where('site_key', $site)->whereNotNull('public_revision_id')->count();
        if ($count <= 500) {
            $this->buildStep($site);

            return Cache::get($key);
        }
        if (! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['sync', 'null'], true) && Cache::add($key.':queued', true, 60)) {
            BuildTopicSitemapManifest::dispatch($site)->onQueue('geoflow');
        }

        return is_array($active) ? $active : null;
    }

    public function buildStep(string $site): bool
    {
        $key = 'topics:sitemap:'.$site;

        return Cache::lock($key.':lock', 60)->block(2, function () use ($key, $site): bool {
            $version = $this->version($site);
            $active = Cache::get($key);
            if (is_array($active) && $active['version'] === $version) {
                return true;
            }
            $pending = Cache::get($key.':pending');
            if (! is_array($pending) || $pending['version'] !== $version) {
                $pending = ['version' => $version, 'after' => 0, 'count' => 0, 'boundaries' => [0], 'lastmod' => null];
            }
            $rows = Topic::query()->where('site_key', $site)->whereNotNull('public_revision_id')->where('id', '>', $pending['after'])->orderBy('id')->with('publicRevision.articles')->limit(500)->get();
            app(TopicViewBuilder::class)->withReadBatch($site, $rows, function () use ($rows, &$pending): void {
                foreach ($rows as $topic) {
                    $pending['after'] = $topic->id;
                    $view = app(TopicService::class)->publicView($topic);
                    if (! $view) {
                        continue;
                    }
                    $pending['count']++;
                    $pending['lastmod'] = max($pending['lastmod'] ?? '', $view['modified_at'] ?? '');
                    if (($pending['count'] + 1) % $this->limit() === 0) {
                        $pending['boundaries'][] = $topic->id;
                    }
                }
            });
            if ($this->version($site) !== $version) {
                Cache::forget($key.':pending');

                return false;
            }
            if ($rows->count() === 500) {
                Cache::put($key.':pending', $pending, 86400);

                return false;
            }
            $pending['pages'] = $pending['count'] > 0 ? (int) ceil(($pending['count'] + 1) / $this->limit()) : 0;
            $pending['boundaries'] = array_slice($pending['boundaries'], 0, $pending['pages']);
            Cache::forever($key, $pending);
            Cache::forget($key.':pending');
            Cache::forget($key.':queued');

            return true;
        });
    }

    private function version(string $site): array
    {
        $row = Topic::query()->where('site_key', $site)->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as modified, max(public_revision_id) as revision, count(public_revision_id) as published')->first();

        return [$row->only(['total', 'last_id', 'modified', 'revision', 'published']), TopicSourceInvalidation::query()->whereHas('topic', fn ($q) => $q->where('site_key', $site))->max('id'), app(TopicSiteSettings::class)->get($site), $this->limit()];
    }
}
