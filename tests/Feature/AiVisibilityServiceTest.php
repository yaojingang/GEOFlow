<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiVisibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_doubao_search_custom_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_search_1',
                'Result' => [
                    'WebResults' => [
                        [
                            'Title' => 'GEOFlow 介绍',
                            'SiteName' => 'GEOFlow',
                            'Url' => 'https://example.com/geoflow',
                            'Snippet' => 'GEOFlow 帮助内容进入 AI 回答。',
                            'Summary' => 'GEOFlow 的 AI 可见性能力。',
                            'Content' => '完整搜索结果内容',
                            'RankScore' => 0.91,
                        ],
                    ],
                ],
            ]),
        ]);

        $provider = $this->createSearchProvider();

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow AI 可见性', [
            'count' => 2,
        ]);

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM, $run->provider_type);
        $this->assertSame('GEOFlow AI 可见性', $run->keyword);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(1, (int) $provider->fresh()->used_today);
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'title' => 'GEOFlow 介绍',
            'domain' => 'example.com',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.feedcoopapi.com/search_api/web_search'
            && $request->hasHeader('Authorization', 'Bearer test-search-key')
            && $request['Query'] === 'GEOFlow AI 可见性'
            && $request['Count'] === 2
            && ($request['Filter']['NeedContent'] ?? null) === true);
    }

    public function test_it_uses_source_provider_metadata_as_default_search_options(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_search_metadata',
                'Result' => [
                    'WebResults' => [],
                ],
            ]),
        ]);

        $provider = $this->createSearchProvider([
            'metadata_json' => [
                'count' => 4,
                'search_type' => 'web',
                'need_summary' => false,
                'need_content' => false,
                'need_url' => true,
                'content_formats' => 'Text',
                'auth_info_level' => 'high',
                'sites' => ['geoflow.example'],
                'block_hosts' => ['blocked.example'],
            ],
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.feedcoopapi.com/search_api/web_search'
            && $request['Query'] === 'GEOFlow'
            && $request['Count'] === 4
            && ($request['NeedSummary'] ?? null) === false
            && ($request['AuthInfoLevel'] ?? null) === 'high'
            && ($request['Filter']['NeedContent'] ?? null) === false
            && ($request['Filter']['NeedUrl'] ?? null) === true
            && ($request['Filter']['ContentFormats'] ?? null) === 'Text'
            && ($request['Filter']['Sites'] ?? null) === ['geoflow.example']
            && ($request['Filter']['BlockHosts'] ?? null) === ['blocked.example']);
    }

    public function test_it_persists_doubao_ark_responses_answer_and_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp_ark_1',
                'output' => [
                    [
                        'type' => 'web_search_call',
                        'id' => 'ws_ark_1',
                        'status' => 'completed',
                    ],
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => '豆包返回的可见性回答。',
                                'annotations' => [
                                    [
                                        'title' => 'GEOFlow source',
                                        'url' => 'https://example.com/source',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 21,
                    'output_tokens' => 34,
                ],
            ]),
        ]);

        $model = $this->createAiModel([
            'name' => '火山方舟测试模型',
            'model_id' => 'doubao-seed-2-0-lite-260428',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'max_tokens' => 2048,
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoArkResponses($model, 'GEOFlow', '请搜索 GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('豆包返回的可见性回答。', $run->answer_text);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'title' => 'GEOFlow source',
            'domain' => 'example.com',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/responses'
            && $request->hasHeader('Authorization', 'Bearer test-model-key')
            && $request['model'] === 'doubao-seed-2-0-lite-260428'
            && $request['max_output_tokens'] === 2048
            && ($request['tools'][0]['type'] ?? null) === 'web_search'
            && ($request['input'][0]['content'][0]['text'] ?? null) === '请搜索 GEOFlow');
    }

    public function test_it_marks_doubao_search_custom_run_failed_with_provider_body(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'error' => ['message' => 'bad api key'],
            ], 401),
        ]);

        $provider = $this->createSearchProvider();

        try {
            app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');
            $this->fail('Expected Doubao Search Custom failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
            $this->assertStringContainsString('bad api key', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('HTTP 401', (string) $run->error_message);
        $this->assertStringContainsString('bad api key', (string) $run->error_message);
        $this->assertSame(0, (int) $provider->fresh()->used_today);
    }

    public function test_it_does_not_call_doubao_search_custom_when_provider_limit_is_exhausted(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $provider = $this->createSearchProvider([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);

        try {
            app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');
            $this->fail('Expected exhausted source provider to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('已达到每日调用上限', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('已达到每日调用上限', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_it_resets_yesterdays_provider_usage_before_calling_search(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_new_day',
                'Result' => ['WebResults' => []],
            ]),
        ]);

        $provider = $this->createSearchProvider([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->subDay()->toDateString(),
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $provider->refresh();
        $this->assertSame(now()->toDateString(), $provider->usage_date?->toDateString());
        $this->assertSame(1, (int) $provider->used_today);
    }

    public function test_it_does_not_call_ark_responses_when_model_is_inactive(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $model = $this->createAiModel([
            'status' => 'inactive',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'model_id' => 'doubao-seed-2-0-lite-260428',
        ]);

        try {
            app(AiVisibilityService::class)->runDoubaoArkResponses($model, 'GEOFlow');
            $this->fail('Expected inactive model to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('不可用或已停用', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('不可用或已停用', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_it_persists_deepseek_analysis_usage_from_ai_sdk_response(): void
    {
        MarkdownContentWriterAgent::fake(['分析完成'])->preventStrayPrompts();

        $model = $this->createAiModel();

        $run = app(AiVisibilityService::class)->runDeepSeekAnalysis($model, 'GEOFlow', '请分析 GEOFlow 的 AI 可见性');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS, $run->provider_type);
        $this->assertSame('分析完成', $run->answer_text);
        $this->assertSame([
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 0,
        ], $run->usage_json);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_it_uses_the_models_max_tokens_for_deepseek_analysis_by_default(): void
    {
        MarkdownContentWriterAgent::fake(['分析完成'])->preventStrayPrompts();

        $model = $this->createAiModel(['max_tokens' => 2048]);

        app(AiVisibilityService::class)->runDeepSeekAnalysis($model, 'GEOFlow', '请分析 GEOFlow 的 AI 可见性');

        MarkdownContentWriterAgent::assertPrompted(
            fn ($prompt): bool => $prompt->agent instanceof MarkdownContentWriterAgent
                && $prompt->agent->maxTokens === 2048,
        );
    }

    private function createSearchProvider(array $overrides = []): AiSourceProvider
    {
        return AiSourceProvider::query()->create(array_merge([
            'name' => 'Doubao Search Custom',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-search-key'),
            'status' => 'active',
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
        ], $overrides));
    }

    private function createAiModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'DeepSeek V4 Flash',
            'version' => 'v4',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-model-key'),
            'model_id' => 'deepseek-v4-flash',
            'model_type' => 'chat',
            'api_url' => 'https://api.deepseek.com',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
