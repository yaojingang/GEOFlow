<?php

namespace App\Support\GeoFlow;

/**
 * 当前数据库向量能力探测结果。
 */
final class VectorStoreCapabilities
{
    public function __construct(
        public readonly string $driver,
        public readonly bool $available,
        public readonly int $dimensions,
        public readonly ?string $reason = null,
    ) {}

    public static function unavailable(string $driver, string $reason): self
    {
        return new self(
            driver: $driver,
            available: false,
            dimensions: VectorStoreAdapter::DEFAULT_DIMENSIONS,
            reason: $reason,
        );
    }
}
