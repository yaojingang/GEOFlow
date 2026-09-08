<?php

namespace Tests\Feature;

use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\Admin\AiModelUsageAccessSnapshot;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageRecorder;
use App\Services\GeoFlow\ArticleAiQualityProviderUsageSession;
use App\Services\GeoFlow\ArticleAiQualityReadinessRecorder;
use App\Services\GeoFlow\LaravelArticleAiQualityReviewer;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaravelArticleAiQualityReviewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    #[DataProvider('wrappedResponses')]
    public function test_complete_wrapped_json_is_recovered_without_another_provider_call(string $prefix, string $suffix, string $version): void
    {
        $model = $this->model();
        $expected = $this->validResult($version);
        Http::fake(['*' => Http::response($this->response($prefix.json_encode($expected).$suffix))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->reviewWithinVersion($model, 'Review this article.', 30, $version);

        $this->assertSame($expected, $review['result']);
        $this->assertSame(19, $review['usage']['completion_tokens']);
        Http::assertSentCount(1);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public static function wrappedResponses(): array
    {
        return [
            'plain JSON' => ['', '', 'fast_v2'],
            'code fence' => ["```json\n", "\n```", 'fast_v2'],
            'thinking then fence' => ["<think>Analyze {the article}.</think>\n```json\n", "\n```", 'fast_v2'],
            'thinking and legacy schema' => ["<think>Analyze.</think>\n", '', 'legacy'],
        ];
    }

    #[DataProvider('invalidStructuredResponses')]
    public function test_invalid_structured_result_enters_json_fallback_and_records_both_attempts(string $content): void
    {
        $model = $this->model();
        Http::fake(['*' => Http::sequence()
            ->push($this->response($content))
            ->push($this->response(json_encode($this->validResult())))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');

        $this->assertSame($this->validResult(), $review['result']);
        $this->assertSame('json_fallback', $review['mode']);
        Http::assertSentCount(2);
        $fresh = $model->fresh();
        $this->assertSame(2, (int) $fresh->used_today);
        $this->assertSame(1, (int) $fresh->total_used);
        $samples = $fresh->ai_workspace_readiness_profile['article_quality_structured_output']['_samples'];
        $this->assertSame(['structured', 'json_fallback'], array_column($samples, 'mode'));
        $this->assertSame([false, true], array_column($samples, 'schema_passed'));
    }

    public static function invalidStructuredResponses(): array
    {
        return [[''], ['{}'], ['The article looks good.']];
    }

    public function test_cached_json_fallback_also_accepts_a_thinking_prefix(): void
    {
        $model = $this->model();
        app(ArticleAiQualityReadinessRecorder::class)->recordAttempt($model, 'structured', false, 10, 'invalid_model_output');
        Http::fake(['*' => Http::response($this->response("<think>Analyze.</think>\n```json\n".json_encode($this->validResult())."\n```"))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->review($model->fresh(), 'Review this article.');

        $this->assertSame($this->validResult(), $review['result']);
        $this->assertSame('json_fallback', $review['mode']);
        Http::assertSentCount(1);
    }

    #[DataProvider('truncatedResponses')]
    public function test_truncation_is_reported_without_a_same_budget_json_retry(string $content, string $finishReason): void
    {
        $model = $this->model();
        Http::fake(['*' => Http::response($this->response($content, $finishReason))]);

        try {
            app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');
            $this->fail('Expected truncated output to fail.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('model_output_truncated', $exception->safeCode());
            $this->assertTrue($exception->retryable());
        }
        Http::assertSentCount(1);
    }

    public static function truncatedResponses(): array
    {
        return [
            'only reasoning exhausted budget' => ['', 'length'],
            'partial JSON' => ['{"summary":"unfinished', 'stop'],
            'apparently valid JSON with length finish' => [json_encode(self::validResult()), 'length'],
        ];
    }

    public function test_invalid_fallback_remains_a_failure_and_does_not_expose_response_content(): void
    {
        $model = $this->model();
        Http::fake(['*' => Http::sequence()
            ->push($this->response('invalid response'))
            ->push($this->response('<think>private fixture text'))]);

        try {
            app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');
            $this->fail('Expected invalid output to fail.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('invalid_model_output', $exception->safeCode());
            $this->assertStringNotContainsString('private fixture text', (string) $exception);
        }
        Http::assertSentCount(2);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_thinking_tags_inside_a_json_value_are_preserved(): void
    {
        $model = $this->model();
        $expected = $this->validResult();
        $expected['summary'] = 'Quoted <think>article text</think> is preserved.';
        Http::fake(['*' => Http::response($this->response("```json\n".json_encode($expected)."\n```"))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');

        $this->assertSame($expected, $review['result']);
        Http::assertSentCount(1);
    }

    public function test_discarded_structured_response_retains_usage_and_fallback_is_finalized_once(): void
    {
        $admin = Admin::query()->create([
            'username' => 'quality-usage-fixture',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $model = $this->model();
        $model->forceFill(['owner_admin_id' => $admin->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
        $snapshot = AiModelUsageAccessSnapshot::capture(
            $model, $admin, AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
            AiModelUsageEvent::MODEL_SOURCE_PERSONAL, (int) $admin->ai_config_access_version,
            (string) Str::uuid(), hash('sha256', 'quality fixture'),
        );
        $session = new ArticleAiQualityProviderUsageSession(fn (string $mode): AiModelUsageAttempt => new AiModelUsageAttempt(
            $snapshot, app(AiModelUsageRecorder::class), [
                'call_key' => 'quality-'.$mode,
                'operation' => 'article_ai_quality.inspect',
                'business_source' => 'article_ai_quality',
                'source_type' => null,
                'source_id' => null,
            ],
        ));
        Http::fake(['*' => Http::sequence()
            ->push($this->response('invalid result'))
            ->push($this->response(json_encode($this->validResult())))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->reviewWithinVersionTrackingProviderAttempts(
            $model, 'Review this article.', 30, 'fast_v2', $session,
        );
        $session->succeeded();
        $session->succeeded();

        $this->assertSame($this->validResult(), $review['result']);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame([AiModelUsageEvent::STATUS_DISCARDED, AiModelUsageEvent::STATUS_SUCCEEDED], $events->pluck('status')->all());
        $this->assertSame(['invalid_model_output', null], $events->pluck('error_code')->all());
        $this->assertSame([19, 19], $events->pluck('output_tokens')->all());
        $this->assertSame([30, 30], $events->pluck('total_tokens')->all());
        Http::assertSentCount(2);
    }

    public function test_invalid_output_cannot_bypass_the_remaining_model_quota(): void
    {
        $model = $this->model();
        $model->forceFill(['daily_limit' => 1])->save();
        Http::fake(['*' => Http::response($this->response('invalid result'))]);

        try {
            app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');
            $this->fail('Expected the fallback reservation to be denied.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('provider_quota_exhausted', $exception->safeCode());
        }
        Http::assertSentCount(1);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_authentication_failure_does_not_trigger_json_fallback(): void
    {
        $model = $this->model();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        try {
            app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');
            $this->fail('Expected authentication failure.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('provider_authentication_failed', $exception->safeCode());
        }
        Http::assertSentCount(1);
    }

    public function test_unknown_gateway_keeps_its_original_request_options(): void
    {
        $model = $this->model('https://api.minimaxi.com.example.test/v1', 'MiniMax-M2.5');
        Http::fake(['*' => Http::response($this->response(json_encode($this->validResult())))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');

        $this->assertSame($this->validResult(), $review['result']);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('json_schema', $request['response_format']['type']);
            $this->assertSame(2048, $request['max_tokens']);
            $this->assertArrayNotHasKey('reasoning_split', $request->data());
            $this->assertArrayNotHasKey('thinking', $request->data());

            return true;
        });
    }

    #[DataProvider('officialProviders')]
    public function test_official_provider_options_reach_the_real_sdk_request(string $url, string $modelId, array $options, bool $jsonPreferred = false): void
    {
        $model = $this->model($url, $modelId);
        if ($jsonPreferred) {
            app(ArticleAiQualityReadinessRecorder::class)->recordAttempt($model, 'structured', false, 10, 'invalid_model_output');
            $model = $model->fresh();
        }
        Http::fake(['*' => Http::response($this->response(json_encode($this->validResult())))]);

        $review = app(LaravelArticleAiQualityReviewer::class)->review($model, 'Review this article.');

        $this->assertSame($this->validResult(), $review['result']);
        Http::assertSent(function (Request $request) use ($options): bool {
            foreach ($options as $key => $value) {
                $this->assertSame($value, $request[$key]);
            }

            return true;
        });
        Http::assertSentCount(1);
    }

    public static function officialProviders(): array
    {
        return [
            'GLM China' => ['https://open.bigmodel.cn/api/paas/v4', 'glm-4.7', ['thinking' => ['type' => 'disabled'], 'response_format' => ['type' => 'json_object']]],
            'GLM international' => ['https://api.z.ai/api/paas/v4', 'glm-5', ['thinking' => ['type' => 'disabled'], 'response_format' => ['type' => 'json_object']]],
            'MiniMax China' => ['https://api.minimaxi.com/v1', 'MiniMax-M2.5', ['reasoning_split' => true]],
            'MiniMax international' => ['https://api.minimax.io/v1', 'MiniMax-M2.1', ['reasoning_split' => true]],
            'GLM cached JSON mode' => ['https://open.bigmodel.cn/api/paas/v4', 'glm-4.5-air', ['thinking' => ['type' => 'disabled'], 'response_format' => ['type' => 'json_object']], true],
            'MiniMax cached JSON mode' => ['https://api.minimaxi.com/v1', 'MiniMax-M2.5', ['reasoning_split' => true], true],
            'DeepSeek runtime provider' => ['https://api.deepseek.com/v1', 'deepseek-v4-flash', ['thinking' => ['type' => 'disabled'], 'max_tokens' => 2048]],
        ];
    }

    private function model(string $url = 'https://quality.example.test/v1', string $modelId = 'quality-model'): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Quality fixture',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-quality-key'),
            'api_url' => $url,
            'model_id' => $modelId,
            'max_tokens' => 4096,
            'status' => 'active',
        ]);
    }

    private static function validResult(string $version = 'fast_v2'): array
    {
        $common = ['summary' => 'Checked.', 'promotion_context' => 'informational', 'issues' => [], 'uncertainties' => []];

        return $version === 'legacy'
            ? [...$common, 'knowledge_coverage' => 'sufficient']
            : [...$common, 'reviewed_claim_hashes' => [], 'truncated_issue_count' => 0];
    }

    private function response(string $content, string $finishReason = 'stop'): array
    {
        return [
            'model' => 'quality-fixture',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finishReason]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 19, 'total_tokens' => 30],
        ];
    }
}
