<?php

namespace App\Services\Outbound;

use App\Contracts\Outbound\HostResolver;
use Closure;

final class SystemHostResolver implements HostResolver
{
    /** @var Closure(string): array<int, array<string, mixed>> */
    private readonly Closure $lookup;

    /**
     * @param  (Closure(string): array<int, array<string, mixed>>)|null  $lookup
     * @param  (Closure(string, int): array<int, array<string, mixed>>)|null  $dnsLookup
     */
    public function __construct(?Closure $lookup = null, ?Closure $dnsLookup = null)
    {
        if ($lookup instanceof Closure) {
            $this->lookup = $lookup;

            return;
        }

        $dnsLookup ??= static fn (string $host, int $type): array => @dns_get_record($host, $type) ?: [];
        $this->lookup = static function (string $host) use ($dnsLookup): array {
            $records = [
                ...$dnsLookup($host, DNS_A),
                ...$dnsLookup($host, DNS_AAAA),
            ];

            foreach ($records as $record) {
                if (in_array(strtoupper((string) ($record['type'] ?? '')), ['A', 'AAAA'], true)) {
                    return $records;
                }
            }

            return [...$records, ...$dnsLookup($host, DNS_CNAME)];
        };
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
        $aliases = [];
        foreach (($this->lookup)($host) as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'A' && filter_var($record['ip'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $addresses[] = (string) $record['ip'];
            } elseif ($type === 'AAAA' && filter_var($record['ipv6'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $addresses[] = strtolower((string) $record['ipv6']);
            } elseif ($type === 'CNAME') {
                $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
                if ($target !== '') {
                    $aliases[] = $target;
                }
            }
        }

        // 系统解析器通常会在 CNAME 记录旁返回最终 A/AAAA 地址，直接使用可避免重复 DNS 查询。
        if ($addresses !== []) {
            return array_values(array_unique($addresses));
        }

        foreach (array_unique($aliases) as $target) {
            $addresses = [...$addresses, ...$this->resolveHost($target, $visited, $depth + 1)];
        }

        return array_values(array_unique($addresses));
    }
}
