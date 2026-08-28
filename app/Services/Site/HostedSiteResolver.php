<?php

namespace App\Services\Site;

use App\Models\HostedSiteProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class HostedSiteResolver
{
    private const NOT_FOUND = 0;

    public function isPrimaryHost(string $hostname): bool
    {
        return $this->rootDomainFor($hostname) === null
            && in_array($hostname, config('geoflow.hosted_sites.primary_hosts', []), true);
    }

    public function isSingleLabelHostedHostname(string $hostname): bool
    {
        return $this->rootDomainFor($hostname) !== null;
    }

    public function rootDomainFor(string $hostname): ?string
    {
        foreach (config('geoflow.hosted_sites.root_domains', []) as $rootDomain) {
            $suffix = '.'.$rootDomain;
            if (! str_ends_with($hostname, $suffix)) {
                continue;
            }

            $label = substr($hostname, 0, -strlen($suffix));

            if ($label !== ''
                && ! str_contains($label, '.')
                && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1
                && ! in_array($label, config('geoflow.hosted_sites.reserved_labels', []), true)) {
                return $rootDomain;
            }
        }

        return null;
    }

    public function findHostedProfile(string $hostname): ?HostedSiteProfile
    {
        if (! config('geoflow.hosted_sites.enabled', false)
            || ! $this->isSingleLabelHostedHostname($hostname)
            || ! Schema::hasTable('hosted_site_profiles')) {
            return null;
        }

        $version = (int) Cache::get('geoflow.hosted_sites.resolver_version', 1);
        $cacheKey = 'geoflow.hosted_sites.resolve.'.$version.'.'.hash('sha256', $hostname);
        $ttl = max(1, (int) config('geoflow.hosted_sites.resolver_positive_ttl', 300));

        $profileId = Cache::remember($cacheKey, $ttl, static function () use ($hostname): int {
            return (int) (HostedSiteProfile::query()
                ->where('hostname', $hostname)
                ->value('id') ?? self::NOT_FOUND);
        });

        if ($profileId === self::NOT_FOUND) {
            Cache::put(
                $cacheKey,
                self::NOT_FOUND,
                max(1, (int) config('geoflow.hosted_sites.resolver_negative_ttl', 30))
            );

            return null;
        }

        return HostedSiteProfile::query()->with('channel')->find($profileId);
    }

    public function invalidate(?string $hostname = null): void
    {
        if ($hostname !== null) {
            $version = (int) Cache::get('geoflow.hosted_sites.resolver_version', 1);
            Cache::forget('geoflow.hosted_sites.resolve.'.$version.'.'.hash('sha256', $hostname));

            return;
        }

        Cache::increment('geoflow.hosted_sites.resolver_version');
    }
}
