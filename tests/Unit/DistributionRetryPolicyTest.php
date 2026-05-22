<?php

namespace Tests\Unit;

use App\Services\GeoFlow\DistributionRetryPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DistributionRetryPolicyTest extends TestCase
{
    public function test_network_and_rate_limit_errors_are_retryable(): void
    {
        $policy = new DistributionRetryPolicy;

        $this->assertTrue($policy->shouldRetry(new RuntimeException('Connection timed out'), 1, 3));
        $this->assertTrue($policy->shouldRetry(new RuntimeException('HTTP 429 Too Many Requests'), 1, 3));
        $this->assertTrue($policy->shouldRetry(new RuntimeException('HTTP 500 Server Error'), 1, 3));
    }

    public function test_auth_and_signature_errors_are_not_retryable(): void
    {
        $policy = new DistributionRetryPolicy;

        $this->assertFalse($policy->shouldRetry(new RuntimeException('HTTP 401 Unauthorized'), 1, 3));
        $this->assertFalse($policy->shouldRetry(new RuntimeException('HTTP 403 Forbidden'), 1, 3));
        $this->assertFalse($policy->shouldRetry(new RuntimeException('signature invalid'), 1, 3));
    }

    public function test_attempt_limit_stops_retrying(): void
    {
        $policy = new DistributionRetryPolicy;

        $this->assertFalse($policy->shouldRetry(new RuntimeException('HTTP 500 Server Error'), 3, 3));
    }
}
