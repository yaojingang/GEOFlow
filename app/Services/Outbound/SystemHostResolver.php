<?php

namespace App\Services\Outbound;

use App\Contracts\Outbound\HostResolver;
use Closure;

final class SystemHostResolver implements HostResolver
{
    /** @var Closure(string): array<int, array<string, mixed>> */
    private readonly Closure $lookup;

    /** @param (Closure(string): array<int, array<string, mixed>>)|null $lookup */
    public function __construct(?Closure $lookup = null)
    {
        $this->lookup = $lookup ?? static fn (string $host): array => dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME) ?: [];
    }

    public function resolve(string $host): array
    {
        return $this->resolveHost(strtolower(rtrim($host, '.')), [], 0);
    }

    /**
     * @param  array<string, true>  $visited
     * @return list<string>
     */
    private function resolveHost(string $host, array $visited, int $depth): array
    {
        if ($depth > 8 || isset($visited[$host])) {
            return [];
        }

        $visited[$host] = true;
        $addresses = [];
        foreach (($this->lookup)($host) as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'A' && filter_var($record['ip'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $addresses[] = (string) $record['ip'];
            } elseif ($type === 'AAAA' && filter_var($record['ipv6'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $addresses[] = strtolower((string) $record['ipv6']);
            } elseif ($type === 'CNAME') {
                $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
                if ($target !== '') {
                    $addresses = [...$addresses, ...$this->resolveHost($target, $visited, $depth + 1)];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
