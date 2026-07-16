<?php

namespace App\Services\Outbound;

final readonly class ResolvedOutboundTarget
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public array $addresses,
        public string $selectedIp,
    ) {}
}
