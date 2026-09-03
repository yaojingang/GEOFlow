<?php

namespace App\Services\GeoFlow;

final readonly class TitleGenerationOutcome
{
    private const STATUS_SUCCESS = 'success';

    private const STATUS_QUOTA_EXHAUSTED = 'quota_exhausted';

    private const STATUS_FAILED = 'failed';

    /**
     * @param  list<string>  $titles
     */
    private function __construct(
        private string $status,
        public array $titles,
        public ?string $failureCode,
        public bool $retryable,
        public ?TitleGenerationUsageDelivery $usageDelivery,
    ) {}

    /** @param  list<string>  $titles */
    public static function success(array $titles, ?TitleGenerationUsageDelivery $usageDelivery = null): self
    {
        return new self(self::STATUS_SUCCESS, $titles, null, false, $usageDelivery);
    }

    public static function quotaExhausted(): self
    {
        return new self(self::STATUS_QUOTA_EXHAUSTED, [], 'ai_daily_limit_reached', true, null);
    }

    public static function failed(string $failureCode, bool $retryable = false): self
    {
        return new self(self::STATUS_FAILED, [], $failureCode, $retryable, null);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function quotaWasExhausted(): bool
    {
        return $this->status === self::STATUS_QUOTA_EXHAUSTED;
    }
}
