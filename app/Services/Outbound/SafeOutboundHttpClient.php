<?php

namespace App\Services\Outbound;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class SafeOutboundHttpClient
{
    /** @var list<string> */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_IPV6_CIDRS = [
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '100:0:0:1::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /** @var list<string> */
    private const UNSAFE_TRANSITION_IPV6_CIDRS = [
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2001::/32',
        '2002::/16',
    ];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly OutboundTransport $transport,
    ) {}

    /** @param array<string, mixed> $query */
    public function get(
        PendingRequest $request,
        string $url,
        int $maxBytes,
        int $maxRedirects = 0,
        array $query = [],
        ?callable $redirectValidator = null,
    ): Response {
        return $this->send($request, 'GET', $url, $query, $maxBytes, $maxRedirects, $redirectValidator);
    }

    /** @param array<string, mixed> $data */
    public function post(PendingRequest $request, string $url, array $data, int $maxBytes): Response
    {
        return $this->send($request, 'POST', $url, $data, $maxBytes);
    }

    /** @param array<string, mixed> $data */
    public function delete(PendingRequest $request, string $url, array $data, int $maxBytes): Response
    {
        return $this->send($request, 'DELETE', $url, $data, $maxBytes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(
        PendingRequest $request,
        string $method,
        string $url,
        array $data,
        int $maxBytes,
        int $maxRedirects = 0,
        ?callable $redirectValidator = null,
    ): Response {
        if ($maxBytes < 1 || $maxRedirects < 0 || $maxRedirects > 3) {
            throw new OutboundRequestBlockedException('invalid_request_policy');
        }

        $method = strtoupper($method);
        $redirects = 0;
        $currentUrl = $url;
        $currentRequest = clone $request;
        $currentData = $data;
        $previousTarget = null;

        while (true) {
            $target = $this->resolveTarget($currentUrl);
            $crossOrigin = $previousTarget instanceof ResolvedOutboundTarget && ! $this->sameOrigin($previousTarget, $target);
            if ($crossOrigin) {
                $currentRequest = CrossOriginRequestSanitizer::pendingRequest($currentRequest, $method, $target->url);
                if (in_array($method, ['GET', 'HEAD'], true)) {
                    $currentData = [];
                }
            }

            try {
                $response = $this->transport->send($currentRequest, $method, $target, $currentData, $maxBytes, $crossOrigin);
            } catch (OutboundRequestBlockedException|OutboundRequestFailedException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw new OutboundRequestFailedException;
            }
            $this->assertResponseSize($response, $maxBytes);

            if (! $this->isRedirect($response)) {
                return $response;
            }

            $location = trim((string) $response->header('Location', ''));
            if ($location === '') {
                return $response;
            }
            if ($redirects >= $maxRedirects) {
                throw new OutboundRequestBlockedException('redirect_limit_exceeded');
            }

            $redirectUrl = $this->redirectUrl($target->url, $location);
            if ($redirectValidator !== null) {
                $redirectValidator($redirectUrl);
            }

            $previousTarget = $target;
            $currentUrl = $redirectUrl;
            $redirects++;
            $status = $response->status();
            if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) {
                $method = 'GET';
                $currentData = [];
                $currentRequest = $this->clearRequestEntity($currentRequest);
            }
        }
    }

    public function resolveTarget(string $url): ResolvedOutboundTarget
    {
        [$normalizedUrl, $scheme, $host, $port] = $this->normalizeUrl($url);
        $ipLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $ipLiteral && ! str_contains($host, '.') && ! $this->privateTargetAllowed($host, $port)) {
            throw new OutboundRequestBlockedException('unsafe_address');
        }
        try {
            $addresses = $ipLiteral ? [$host] : $this->resolver->resolve($host);
        } catch (\Throwable) {
            throw new OutboundRequestFailedException;
        }
        $addresses = array_values(array_unique(array_map(static fn (mixed $ip): string => strtolower(trim((string) $ip)), $addresses)));
        if ($addresses === [] || in_array('', $addresses, true)) {
            throw new OutboundRequestBlockedException('dns_resolution_failed');
        }

        $allowPrivate = $this->privateTargetAllowed($host, $port);
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new OutboundRequestBlockedException('invalid_address');
            }
            if ($this->isIpv4MappedAddress($ip)) {
                throw new OutboundRequestBlockedException('mapped_address');
            }
            if ($this->isUnsafeTransitionAddress($ip)) {
                throw new OutboundRequestBlockedException('unsafe_address');
            }
            if (! $allowPrivate && ! $this->isPublicAddress($ip)) {
                throw new OutboundRequestBlockedException('unsafe_address');
            }
        }

        return new ResolvedOutboundTarget($normalizedUrl, $scheme, $host, $port, $addresses, $addresses[0]);
    }

    /** @return array{string, string, string, int} */
    private function normalizeUrl(string $url): array
    {
        if ($url === '' || $url !== trim($url)) {
            throw new OutboundRequestBlockedException('invalid_url');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new OutboundRequestBlockedException('control_character');
        }
        if (! preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)~', $url, $authorityMatch)) {
            throw new OutboundRequestBlockedException('invalid_scheme');
        }
        $authority = (string) $authorityMatch[2];
        if (str_contains($authority, '%') || str_contains($authority, '\\')) {
            throw new OutboundRequestBlockedException('ambiguous_authority');
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new OutboundRequestBlockedException('invalid_url');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundRequestBlockedException('invalid_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundRequestBlockedException('userinfo_forbidden');
        }
        if (array_key_exists('fragment', $parts)) {
            throw new OutboundRequestBlockedException('fragment_forbidden');
        }

        $rawHost = strtolower((string) ($parts['host'] ?? ''));
        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $rawHost = substr($rawHost, 1, -1);
        }
        $host = rtrim($rawHost, '.');
        if ($host === '' || ! mb_check_encoding($host, 'ASCII')) {
            throw new OutboundRequestBlockedException('invalid_host');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new OutboundRequestBlockedException('unsafe_address');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if ($this->looksLikeAmbiguousIp($host)) {
                throw new OutboundRequestBlockedException('ambiguous_ip');
            }
            if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
                throw new OutboundRequestBlockedException('invalid_host');
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new OutboundRequestBlockedException('invalid_port');
        }

        $hostForUrl = str_contains($host, ':') ? '['.$host.']' : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $normalized = $scheme.'://'.$hostForUrl.($port !== $defaultPort ? ':'.$port : '');
        $normalized .= (string) ($parts['path'] ?? '');
        if (array_key_exists('query', $parts)) {
            $normalized .= '?'.(string) $parts['query'];
        }

        return [$normalized, $scheme, $host, $port];
    }

    private function looksLikeAmbiguousIp(string $host): bool
    {
        if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/i', $host) === 1) {
            return true;
        }
        if (preg_match('/^[0-9.]+$/', $host) !== 1) {
            return false;
        }

        return true;
    }

    private function privateTargetAllowed(string $host, int $port): bool
    {
        $configured = config('geoflow.outbound_private_targets', []);
        $items = is_array($configured) ? $configured : explode(',', (string) $configured);
        $expected = strtolower($host).':'.$port;

        foreach ($items as $item) {
            $candidate = strtolower(trim((string) $item));
            if ($candidate !== '' && ! str_contains($candidate, '*') && ! str_contains($candidate, '/') && $candidate === $expected) {
                return true;
            }
        }

        return false;
    }

    private function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $cidrs = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? self::BLOCKED_IPV4_CIDRS
            : self::BLOCKED_IPV6_CIDRS;

        foreach ($cidrs as $cidr) {
            if ($this->addressMatchesCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function isIpv4MappedAddress(string $ip): bool
    {
        $binary = @inet_pton($ip);

        return is_string($binary)
            && strlen($binary) === 16
            && substr($binary, 0, 10) === str_repeat("\0", 10)
            && substr($binary, 10, 2) === "\xff\xff";
    }

    private function isUnsafeTransitionAddress(string $ip): bool
    {
        foreach (self::UNSAFE_TRANSITION_IPV6_CIDRS as $cidr) {
            if ($this->addressMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function addressMatchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if (! is_string($addressBytes) || ! is_string($networkBytes) || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $byteCount = intdiv($prefix, 8);
        if (substr($addressBytes, 0, $byteCount) !== substr($networkBytes, 0, $byteCount)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$byteCount]) & $mask) === (ord($networkBytes[$byteCount]) & $mask);
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function assertResponseSize(Response $response, int $maxBytes): void
    {
        $declared = $response->header('Content-Length');
        if ((is_numeric($declared) && (int) $declared > $maxBytes) || strlen($response->body()) > $maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
    }

    private function sameOrigin(ResolvedOutboundTarget $first, ResolvedOutboundTarget $second): bool
    {
        return $first->scheme === $second->scheme && $first->host === $second->host && $first->port === $second->port;
    }

    private function clearRequestEntity(PendingRequest $request): PendingRequest
    {
        $headers = $request->getOptions()['headers'] ?? [];
        $cleared = [
            'Content-Length' => '',
            'Expect' => '',
            'Transfer-Encoding' => '',
        ];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (str_starts_with(strtolower((string) $name), 'content-')) {
                $cleared[(string) $name] = '';
            }
        }

        return $request
            ->withBody('', '')
            ->withOptions([
                'form_params' => null,
                'json' => null,
                'multipart' => null,
            ])
            ->replaceHeaders($cleared);
    }

    private function redirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base)) {
            throw new OutboundRequestBlockedException('invalid_redirect');
        }
        $origin = (string) $base['scheme'].'://'.(string) $base['host'];
        if (isset($base['port'])) {
            $origin .= ':'.(int) $base['port'];
        }
        if (str_starts_with($location, '//')) {
            return (string) $base['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = str_ends_with($path, '/') ? $path : dirname($path).'/';

        return $origin.$directory.$location;
    }
}
