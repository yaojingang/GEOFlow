<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Services\GeoFlow\ArticleAiQualityReadinessRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class ArticleAiQualityReadinessRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_failures_and_json_successes_create_a_degraded_quality_profile(): void
    {
        $model = AiModel::query()->create([
            'name' => 'Quality readiness model',
            'api_key' => 'encrypted-test',
            'model_id' => 'quality-readiness',
            'api_url' => 'https://ai.test',
            'status' => 'active',
        ]);
        $recorder = app(ArticleAiQualityReadinessRecorder::class);

        $recorder->recordAttempt($model, 'structured', false, 900, 'structured_output_unsupported');
        $recorder->recordAttempt($model, 'json_fallback', true, 600, null);

        $profile = $model->fresh()->ai_workspace_readiness_profile['article_quality_structured_output'];
        $this->assertSame('degraded', $profile['status']);
        $this->assertEquals(0.0, $profile['schema_pass_rate']);
        $this->assertEquals(1.0, $profile['recent_error_rate']);
        $this->assertSame(600, $profile['latency_ms']['p50']);
        $this->assertSame(900, $profile['latency_ms']['p95']);
        $this->assertTrue($recorder->prefersJson($model->fresh()));
    }

    public function test_a_structured_success_marks_the_quality_profile_ready(): void
    {
        $model = AiModel::query()->create([
            'name' => 'Ready quality model',
            'api_key' => 'encrypted-test',
            'model_id' => 'ready-quality',
            'api_url' => 'https://ai.test',
            'status' => 'active',
        ]);
        $recorder = app(ArticleAiQualityReadinessRecorder::class);

        $recorder->recordAttempt($model, 'structured', true, 420, null);

        $profile = $model->fresh()->ai_workspace_readiness_profile['article_quality_structured_output'];
        $this->assertSame('ready', $profile['status']);
        $this->assertEquals(1.0, $profile['schema_pass_rate']);
        $this->assertFalse($recorder->prefersJson($model->fresh()));
    }

    public function test_json_preference_expires_and_provider_failures_do_not_mark_schema_support_degraded(): void
    {
        config()->set('geoflow.ai_quality_structured_reprobe_seconds', 300);
        $model = AiModel::query()->create([
            'name' => 'Reprobe quality model',
            'api_key' => 'encrypted-test',
            'model_id' => 'reprobe-quality',
            'api_url' => 'https://ai.test',
            'status' => 'active',
        ]);
        $recorder = app(ArticleAiQualityReadinessRecorder::class);
        $recorder->recordAttempt($model, 'structured', false, 900, 'structured_output_unsupported');
        $this->assertTrue($recorder->prefersJson($model->fresh()));
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $recorder->recordAttempt($model->fresh(), 'json_fallback', true, 600, null);
        }
        $this->assertTrue($recorder->prefersJson($model->fresh()));

        $this->travel(301)->seconds();
        $this->assertFalse($recorder->prefersJson($model->fresh()));

        $providerFailure = AiModel::query()->create([
            'name' => 'Provider failure model',
            'api_key' => 'encrypted-test',
            'model_id' => 'provider-failure',
            'api_url' => 'https://ai.test',
            'status' => 'active',
        ]);
        $recorder->recordAttempt($providerFailure, 'structured', false, 1000, 'provider_timeout');
        $this->assertFalse($recorder->prefersJson($providerFailure->fresh()));
    }

    public function test_latest_capability_result_controls_quality_status_without_mutating_workspace_readiness(): void
    {
        $model = AiModel::query()->create([
            'name' => 'Isolated quality readiness model',
            'api_key' => 'encrypted-test',
            'model_id' => 'isolated-quality-readiness',
            'api_url' => 'https://ai.test',
            'status' => 'active',
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
        ]);
        $recorder = app(ArticleAiQualityReadinessRecorder::class);

        $recorder->recordAttempt($model, 'structured', true, 420, null);
        $recorder->recordAttempt($model->fresh(), 'structured', false, 500, 'invalid_model_output');

        $fresh = $model->fresh();
        $profile = $fresh->ai_workspace_readiness_profile['article_quality_structured_output'];
        $this->assertSame('degraded', $profile['status']);
        $this->assertTrue($recorder->prefersJson($fresh));
        $this->assertSame('ready', $fresh->ai_workspace_structured_output_status);
        $this->assertNotNull($fresh->ai_workspace_structured_output_verified_at);

        $fullProfile = $fresh->ai_workspace_readiness_profile;
        Arr::set($fullProfile, 'article_quality_structured_output.last_structured_attempt_at', 'invalid-timestamp');
        $fresh->forceFill(['ai_workspace_readiness_profile' => $fullProfile])->save();

        $this->assertFalse($recorder->prefersJson($fresh->fresh()));
    }
}
