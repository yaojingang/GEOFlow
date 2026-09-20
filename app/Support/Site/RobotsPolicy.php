<?php

namespace App\Support\Site;

final class RobotsPolicy
{
    /** @var list<string> */
    private const BLOCKED_USER_AGENTS = [
        'facebookexternalhit',
        'Facebot',
        'facebookcatalog',
    ];

    /** @return list<string> */
    public static function blockedPaths(): array
    {
        $adminPath = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        return array_values(array_unique([
            '/'.($adminPath !== '' ? $adminPath.'/' : 'geo_admin/'),
            '/api/',
            '/app',
            '/broadcasting/',
            '/config.php',
            '/horizon/',
            '/livewire/',
            '/sanctum/',
            '/storage/',
            '/up',
            '/vendor/',
            '/_boost/',
            '/_debugbar/',
            '/search',
            '/search/',
            '/query',
            '/query/',
            '/find',
            '/find/',
            '/*?*',
            '/*.avif$',
            '/*.gif$',
            '/*.jpeg$',
            '/*.jpg$',
            '/*.png$',
            '/*.svg$',
            '/*.webp$',
        ]));
    }

    /** @return list<string> */
    public static function blockedUserAgents(): array
    {
        return self::BLOCKED_USER_AGENTS;
    }

    public static function render(bool $indexingAllowed, string $sitemapXml, string $sitemapText): string
    {
        if (! $indexingAllowed) {
            return "User-agent: *\nDisallow: /\n";
        }

        $lines = [
            'User-agent: *',
            'Allow: /',
        ];
        foreach (self::blockedPaths() as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        foreach (self::blockedUserAgents() as $userAgent) {
            $lines[] = '';
            $lines[] = 'User-agent: '.$userAgent;
            $lines[] = 'Disallow: /';
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.$sitemapXml;
        $lines[] = 'Sitemap: '.$sitemapText;

        return implode("\n", $lines)."\n";
    }
}
