<?php

namespace App\Services\Outbound;

/**
 * Resolves the outbound proxy for a given target host.
 *
 * Both the proxy URL and at least one host pattern must be configured for the
 * policy to be considered enabled. By default nothing is proxied, which keeps
 * existing AI provider traffic untouched and only opts specific hosts into the
 * WAF-resistant egress path.
 */
final readonly class OutboundProxyPolicy
{
    /**
     * @param  list<string>  $hostPatterns
     */
    public function __construct(
        public ?string $proxyUrl,
        public array $hostPatterns,
    ) {}

    public static function fromConfig(?string $proxyUrl, string $hostList): self
    {
        $proxyUrl = $proxyUrl !== null ? trim($proxyUrl) : '';
        $patterns = array_values(array_filter(
            array_map('trim', explode(',', $hostList)),
            static fn (string $p): bool => $p !== ''
        ));

        return new self($proxyUrl === '' ? null : $proxyUrl, $patterns);
    }

    public function isEnabled(): bool
    {
        return $this->proxyUrl !== null && $this->hostPatterns !== [];
    }

    public function appliesTo(string $host): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        foreach ($this->hostPatterns as $pattern) {
            $needle = strtolower(trim($pattern));
            if ($needle === '' || $needle === '*' || $needle === '.') {
                return true;
            }
            if (str_starts_with($needle, '*.')) {
                $suffix = substr($needle, 1);
                if ($suffix !== '' && strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return true;
                }

                continue;
            }
            if ($needle === $host) {
                return true;
            }
        }

        return false;
    }
}
