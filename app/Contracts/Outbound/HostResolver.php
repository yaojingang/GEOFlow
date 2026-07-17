<?php

namespace App\Contracts\Outbound;

interface HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
