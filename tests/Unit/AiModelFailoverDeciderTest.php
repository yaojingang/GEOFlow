<?php

namespace Tests\Unit;

use App\Support\GeoFlow\AiModelFailoverDecider;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiModelFailoverDeciderTest extends TestCase
{
    #[DataProvider('permanentStatusProvider')]
    public function test_permanent_provider_responses_are_not_failoverable_or_retryable(int $status): void
    {
        $exception = $this->requestException($status);
        $decider = new AiModelFailoverDecider;

        $this->assertTrue($decider->isPermanentProviderFailure($exception));
        $this->assertFalse($decider->shouldFailover($exception));
    }

    /** @return array<string, array{int}> */
    public static function permanentStatusProvider(): array
    {
        return [
            'invalid credentials' => [401],
            'forbidden model' => [403],
            'invalid parameter' => [400],
            'incompatible capability' => [422],
        ];
    }

    #[DataProvider('transientStatusProvider')]
    public function test_transient_provider_responses_remain_failoverable(int $status): void
    {
        $exception = $this->requestException($status);
        $decider = new AiModelFailoverDecider;

        $this->assertFalse($decider->isPermanentProviderFailure($exception));
        $this->assertTrue($decider->shouldFailover($exception));
    }

    /** @return array<string, array{int}> */
    public static function transientStatusProvider(): array
    {
        return [
            'rate limited' => [429],
            'provider unavailable' => [503],
        ];
    }

    public function test_connection_failures_remain_failoverable(): void
    {
        $exception = new ConnectionException('connection failed');
        $decider = new AiModelFailoverDecider;

        $this->assertFalse($decider->isPermanentProviderFailure($exception));
        $this->assertTrue($decider->shouldFailover($exception));
    }

    private function requestException(int $status): RequestException
    {
        return new RequestException(new Response(new PsrResponse($status, [], '{}')));
    }
}
