<?php

namespace App\Services\GeoFlow\AiVisibility;

final class AiProviderEndpointPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const MODEL_HOSTS = [
        'ark' => ['volces.com'],
        'deepseek' => ['deepseek.com'],
    ];

    public function acceptsModelApi(string $bindingType, string $url): bool
    {
        $trustedHosts = self::MODEL_HOSTS[$bindingType] ?? [];

        return $this->isTrustedHttpsUrl($url, $trustedHosts);
    }

    public function acceptsSearchApi(string $url): bool
    {
        return $this->isTrustedHttpsUrl($url, ['feedcoopapi.com']);
    }

    public function sameOrigin(string $firstUrl, string $secondUrl): bool
    {
        $first = $this->origin($firstUrl);
        $second = $this->origin($secondUrl);

        return $first !== null && $first === $second;
    }

    /**
     * @param  list<string>  $trustedHosts
     */
    private function isTrustedHttpsUrl(string $url, array $trustedHosts): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return false;
        }

        foreach ($trustedHosts as $trustedHost) {
            if ($host === $trustedHost || str_ends_with($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme.'://'.$host.':'.$port;
    }
}
