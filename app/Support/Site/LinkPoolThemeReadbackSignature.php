<?php

namespace App\Support\Site;

use Illuminate\Http\Request;

final class LinkPoolThemeReadbackSignature
{
    public static function forUrl(string $url): string
    {
        $expires = time() + 60;
        $payload = self::payload((string) parse_url($url, PHP_URL_HOST), (string) parse_url($url, PHP_URL_PATH), $expires);

        return $expires.':'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public static function valid(Request $request): bool
    {
        $header = (string) $request->header('X-GEOFlow-Theme-Readback', '');
        if (! $request->isMethod('GET') || ! preg_match('/^([0-9]{10}):([a-f0-9]{64})$/D', $header, $matches)
            || (int) $matches[1] < time() || (int) $matches[1] > time() + 60 || (string) config('app.key') === '') {
            return false;
        }
        $host = self::targetHost($request);
        if ($host === null) {
            return false;
        }
        $expected = hash_hmac('sha256', self::payload($host, $request->getPathInfo(), (int) $matches[1]), (string) config('app.key'));

        return hash_equals($expected, $matches[2]);
    }

    public static function targetHost(Request $request): ?string
    {
        $host = (string) $request->header('X-GEOFlow-Theme-Readback-Host', $request->getHost());

        return strlen($host) <= 253 && preg_match('/^[a-z0-9.-]+$/D', $host) === 1 && ! str_contains($host, '..') ? $host : null;
    }

    private static function payload(string $host, string $path, int $expires): string
    {
        return 'geoflow-theme-readback|'.strtolower($host).'|'.$path.'|'.$expires;
    }
}
