<?php

namespace App\Support\GeoFlow;

use Psr\Http\Message\RequestInterface;

final class OutboundHttpProxy
{
    /**
     * Build Laravel HTTP client options from GEOFlow proxy config.
     *
     * @return array<string, mixed>
     */
    public static function httpClientOptions(): array
    {
        return self::proxyOptions();
    }

    /**
     * Build Laravel HTTP client options for a specific outbound URL.
     *
     * @return array<string, mixed>
     */
    public static function httpClientOptionsForUrl(string $url): array
    {
        if (! self::shouldProxyUrl($url)) {
            return [];
        }

        return self::proxyOptions();
    }

    public static function middleware(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $proxyOptions = self::httpClientOptionsForUrl((string) $request->getUri());

                if (isset($proxyOptions['proxy']) && ! array_key_exists('proxy', $options)) {
                    $options['proxy'] = $proxyOptions['proxy'];
                }

                return $handler($request, $options);
            };
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function proxyOptions(): array
    {
        $httpProxy = trim((string) config('geoflow.outbound_http_proxy', ''));
        $httpsProxy = trim((string) config('geoflow.outbound_https_proxy', $httpProxy));
        $noProxy = self::parseNoProxy(config('geoflow.outbound_no_proxy', ''));

        if ($httpProxy === '' && $httpsProxy === '') {
            return [];
        }

        $proxy = [];
        if ($httpProxy !== '') {
            $proxy['http'] = $httpProxy;
        }
        if ($httpsProxy !== '') {
            $proxy['https'] = $httpsProxy;
        }
        if ($noProxy !== []) {
            $proxy['no'] = $noProxy;
        }

        return ['proxy' => $proxy];
    }

    private static function shouldProxyUrl(string $url): bool
    {
        if (self::proxyOptions() === []) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::parseProxyHosts(config('geoflow.outbound_proxy_hosts', [])) as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (str_ends_with($host, $suffix)) {
                    return true;
                }

                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function parseNoProxy(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value
            ), static fn (string $item): bool => $item !== ''));
        }

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) $value)
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<string>
     */
    private static function parseProxyHosts(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string) $value);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            $items
        ), static fn (string $item): bool => $item !== '')));
    }
}
