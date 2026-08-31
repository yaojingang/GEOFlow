<?php

namespace App\Support\GeoFlow;

final class AiQualityRetrievalResult
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(private readonly array $payload) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
