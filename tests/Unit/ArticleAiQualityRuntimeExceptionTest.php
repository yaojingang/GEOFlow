<?php

namespace Tests\Unit;

use App\Exceptions\ArticleAiQualityCauseException;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Services\GeoFlow\LaravelArticleAiQualityReviewer;
use App\Services\Outbound\OutboundRequestFailedException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class ArticleAiQualityRuntimeExceptionTest extends TestCase
{
    public function test_runtime_failures_retain_only_safe_cause_metadata(): void
    {
        $exception = new ArticleAiQualityRuntimeException(
            'provider_error',
            true,
            new RuntimeException('api_key=secret-value https://private.example.test'),
        );

        $this->assertInstanceOf(ArticleAiQualityCauseException::class, $exception->getPrevious());
        $this->assertSame(RuntimeException::class, $exception->getPrevious()?->causeType);
        $this->assertStringNotContainsString('secret-value', $exception->getPrevious()?->getMessage() ?? '');
        $this->assertNull($exception->getPrevious()?->getPrevious());
    }

    public function test_deepseek_insufficient_balance_is_classified_as_quota_exhausted(): void
    {
        $response = new Response(new PsrResponse(
            402,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => [
                    'code' => 'invalid_request_error',
                    'message' => 'Insufficient Balance',
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $outbound = new OutboundRequestFailedException(new RequestException($response));
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'typedProviderException');

        /** @var ArticleAiQualityRuntimeException $typed */
        $typed = $method->invoke(
            app(LaravelArticleAiQualityReviewer::class),
            $outbound,
            'https://api.deepseek.com/v1',
            null,
        );

        $this->assertSame('provider_quota_exhausted', $typed->safeCode());
        $this->assertFalse($typed->retryable());
        $this->assertSame(402, $typed->httpStatus());
        $this->assertSame('invalid_request_error', $typed->providerCode());
    }

    public function test_direct_provider_http_exception_retains_safe_provider_code(): void
    {
        $response = new Response(new PsrResponse(
            402,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => [
                    'type' => 'unknown_error',
                    'code' => 'invalid_request_error',
                    'message' => 'Insufficient Balance',
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'typedProviderException');

        /** @var ArticleAiQualityRuntimeException $typed */
        $typed = $method->invoke(
            app(LaravelArticleAiQualityReviewer::class),
            new RequestException($response),
            'https://api.deepseek.com/v1',
            null,
        );

        $this->assertSame('provider_quota_exhausted', $typed->safeCode());
        $this->assertSame(402, $typed->httpStatus());
        $this->assertSame('invalid_request_error', $typed->providerCode());
    }

    public function test_truncated_json_output_is_classified_for_sampled_fallback(): void
    {
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'decodeJson');

        try {
            $method->invoke(
                app(LaravelArticleAiQualityReviewer::class),
                '{"summary":"unfinished","issues":[',
            );
            $this->fail('Expected truncated JSON to be rejected.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('model_output_truncated', $exception->safeCode());
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_provider_output_limit_is_classified_for_sampled_fallback(): void
    {
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'typedProviderException');

        /** @var ArticleAiQualityRuntimeException $typed */
        $typed = $method->invoke(
            app(LaravelArticleAiQualityReviewer::class),
            new RuntimeException('Maximum output token limit reached.'),
            'https://api.example.test/v1',
            null,
        );

        $this->assertSame('output_budget_exhausted', $typed->safeCode());
        $this->assertTrue($typed->retryable());
    }
}
