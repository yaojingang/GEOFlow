<?php

namespace App\Services\Outbound;

/**
 * Validates and normalizes the optional egress proxy for outbound requests.
 *
 * The hardened outbound gateway pins every request to a validated IP and
 * disables proxy usage by default. When an operator explicitly opts a host
 * into the egress proxy, the proxy must stay reachable through Guzzle's own
 * proxy option, and trusted cURL connection mapping keeps the target pinned while bypass
 * environment variables are disabled.
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

        // Fixed numeric targets use SOCKS5 address encoding.
        return $scheme === 'socks5h' ? 'socks5'.substr($proxyUrl, strpos($proxyUrl, ':')) : $proxyUrl;
    }
}
