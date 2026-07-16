<?php

namespace Tests\Support;

use App\Contracts\Outbound\HostResolver;

final class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    private array $records = [];

    /** @param list<string> $addresses */
    public function set(string $host, array $addresses): void
    {
        $this->records[strtolower(rtrim($host, '.'))] = $addresses;
    }

    public function resolve(string $host): array
    {
        return $this->records[strtolower(rtrim($host, '.'))] ?? ['93.184.216.34'];
    }
}
