<?php

namespace Tests\Unit;

use App\Services\GeoFlow\DistributionHttpException;
use PHPUnit\Framework\TestCase;

class DistributionHttpExceptionTest extends TestCase
{
    public function test_it_does_not_retain_the_remote_endpoint(): void
    {
        $exception = new DistributionHttpException('Remote request failed.', 502);

        $this->assertSame('Remote request failed.', $exception->getMessage());
        $this->assertSame(502, $exception->status());
        $this->assertFalse(method_exists($exception, 'endpoint'));
    }
}
