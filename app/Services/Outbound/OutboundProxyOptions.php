<?php

namespace App\Services\Outbound;

/**
 * Validates and normalizes the optional egress proxy for outbound requests.
 *
 * The hardened outbound gateway pins every request to a validated IP and
 * disables proxy usage by default. When an operator explicitly opts a host
 * into the egress proxy, the proxy must stay reachable through Guzzle's own
 * proxy option, and the raw cURL "no proxy" overrides must be dropped so the
 * configured proxy is not silently discarded.
 */
final readonly class OutboundProxyOptions
{
    /** @var list<string> */
    public const ALLOWED_SCHEMES = ['http', 'https', 'socks5', 'socks5h'];

    /**
     * @throws OutboundRequestBlockedException when the proxy URL cannot be used safely.
     */
    public static function resolve(mixed $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $proxyUrl = trim($candidate);
        if ($proxyUrl === '') {
            return null;
        }

        $parts = parse_url($proxyUrl);
        if (! is_array($parts)) {
            throw new OutboundRequestBlockedException('outbound_proxy_invalid');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new OutboundRequestBlockedException('outbound_proxy_scheme_forbidden');
        }

        if (trim((string) ($parts['host'] ?? '')) === '') {
            throw new OutboundRequestBlockedException('outbound_proxy_invalid');
        }

        return $proxyUrl;
    }

    /**
     * Removes the raw cURL overrides that force a direct connection.
     *
     * Raw cURL options are applied after Guzzle resolves its own proxy
     * decision, so keeping `CURLOPT_PROXY => ''` or `CURLOPT_NOPROXY => '*'`
     * would silently disable the configured proxy.
     *
     * @param  array<int|string, mixed>  $curlOptions
     * @return array<int|string, mixed>
     */
    public static function withoutDirectConnectionOverrides(array $curlOptions): array
    {
        unset($curlOptions[CURLOPT_PROXY], $curlOptions[CURLOPT_NOPROXY]);

        return $curlOptions;
    }
}
