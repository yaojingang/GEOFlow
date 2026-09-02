<?php

namespace Tests\Unit;

use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\AiModel;
use App\Services\GeoFlow\ArticleAiQualityProviderCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArticleAiQualityProviderCircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        config()->set('geoflow.ai_quality_circuit_consecutive_failures', 5);
        config()->set('geoflow.ai_quality_circuit_sample_size', 10);
        config()->set('geoflow.ai_quality_circuit_failure_percent', 50);
        config()->set('geoflow.ai_quality_circuit_open_seconds', 60);
    }

    public function test_five_consecutive_retryable_failures_open_the_circuit(): void
    {
        $breaker = new ArticleAiQualityProviderCircuitBreaker;
        $model = $this->model();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $breaker->beforeRequest($model);
            $breaker->recordFailure($model, new ArticleAiQualityRuntimeException('provider_timeout', true));
        }

        $this->expectException(ArticleAiQualityRuntimeException::class);
        $this->expectExceptionMessage('provider_circuit_open');
        $breaker->beforeRequest($model);
    }

    public function test_a_success_resets_consecutive_failures(): void
    {
        $breaker = new ArticleAiQualityProviderCircuitBreaker;
        $model = $this->model();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $breaker->recordFailure($model, new ArticleAiQualityRuntimeException('provider_timeout', true));
        }
        $breaker->recordSuccess($model);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $breaker->recordFailure($model, new ArticleAiQualityRuntimeException('provider_timeout', true));
        }

        $breaker->beforeRequest($model);
        $this->addToAssertionCount(1);
    }

    public function test_a_circuit_opened_by_permanent_authentication_failures_is_not_retryable(): void
    {
        config()->set('geoflow.ai_quality_circuit_sample_size', 2);
        $breaker = new ArticleAiQualityProviderCircuitBreaker;
        $model = $this->model();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $breaker->recordFailure(
                $model,
                new ArticleAiQualityRuntimeException('provider_authentication_failed'),
            );
        }

        try {
            $breaker->beforeRequest($model);
            $this->fail('Expected the authentication circuit to remain closed to automatic failover.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('provider_circuit_open', $exception->safeCode());
            $this->assertFalse($exception->retryable());
        }
    }

    private function model(): AiModel
    {
        return new AiModel([
            'id' => 9,
            'model_id' => 'quality-model',
            'api_url' => 'https://ai.test/v1',
        ]);
    }
}
