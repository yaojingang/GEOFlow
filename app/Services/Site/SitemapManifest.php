<?php

namespace App\Services\Site;

use App\Jobs\BuildSitemapManifest;
use Illuminate\Support\Facades\Cache;

final class SitemapManifest
{
    public function __construct(private readonly UrlChangeInspector $inspector, private readonly UrlChangeVersions $versions) {}

    public function limit(): int
    {
        return min(50000, max(2, (int) config('geoflow.hosted_sites.sitemap_url_limit', 50000)));
    }

    /** @param array<string,mixed> $site @return array<string,mixed>|null */
    public function current(array $site): ?array
    {
        $manifest = Cache::get($this->key($site));
        $version = $this->version($site);
        if (! is_array($manifest) || $manifest['version'] !== $version) {
            if (! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['sync', 'null'], true)
                && Cache::add($this->key($site).':queued', true, 60)) {
                BuildSitemapManifest::dispatch($site['key']);
            }
        }

        return is_array($manifest) ? $manifest : null;
    }

    /** One bounded, resumable build step. The previous complete manifest remains readable. */
    public function buildStep(array $site): bool
    {
        return Cache::lock($this->key($site).':lock', 60)->block(2, function () use ($site): bool {
            $version = $this->version($site);
            $active = Cache::get($this->key($site));
            if (is_array($active) && $active['version'] === $version) {
                return true;
            }
            $pendingKey = $this->key($site).':pending';
            $pending = Cache::get($pendingKey);
            if (! is_array($pending) || $pending['version'] !== $version) {
                $pending = ['version' => $version, 'after' => 0, 'count' => 0, 'boundaries' => [0]];
            }
            $ids = $this->inspector->articles($site, true)->where('articles.id', '>', $pending['after'])->orderBy('articles.id')->limit(500)->pluck('articles.id');
            foreach ($ids as $id) {
                $pending['count']++;
                $pending['after'] = (int) $id;
                if (($pending['count'] + 1) % $this->limit() === 0) {
                    $pending['boundaries'][] = (int) $id;
                }
            }
            if ($this->version($site) !== $version) {
                Cache::forget($pendingKey);

                return false;
            }
            if ($ids->count() === 500) {
                Cache::put($pendingKey, $pending, 86400);

                return false;
            }
            $pending['pages'] = max(1, (int) ceil(($pending['count'] + 1) / $this->limit()));
            $pending['boundaries'] = array_slice($pending['boundaries'], 0, $pending['pages']);
            Cache::forever($this->key($site), $pending);
            Cache::forget($pendingKey);
            Cache::forget($this->key($site).':queued');

            return true;
        });
    }

    private function version(array $site): array
    {
        return [$this->versions->snapshot([$site['key']]), $site['policy']['revision'], $this->limit()];
    }

    private function key(array $site): string
    {
        return 'sitemap:manifest:'.$site['key'];
    }
}
