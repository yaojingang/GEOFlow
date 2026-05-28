<?php

namespace App\Support\GeoFlow;

final class OutboundHttpProxy
{
    /**
     * Build Laravel HTTP client options from GEOFlow proxy config.
     *
     * @return array<string, mixed>
     */
    public static function httpClientOptions(): array
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
}
