<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\SiteSetting;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAiSourceProvidersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_configurator_links_to_search_source_configuration(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai.configurator'));

        $response->assertOk()
            ->assertSee(__('admin.ai_configurator.search_title'))
            ->assertSee(route('admin.ai-source-providers.index'), false);
    }

    public function test_ai_configuration_pages_tolerate_provider_table_before_usage_date_migration(): void
    {
        Schema::table('ai_source_providers', function (Blueprint $table): void {
            $table->dropColumn('usage_date');
        });
        $this->createSearchProvider();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai.configurator'))
            ->assertOk()
            ->assertSee(__('admin.ai_configurator.search_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-source-providers.index'))
            ->assertOk()
            ->assertSee('test-search', false);
    }

    public function test_admin_can_view_search_source_page(): void
    {
        $this->createSearchProvider();

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-source-providers.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_source_providers.page_title'))
            ->assertSee(__('admin.ai_source_providers.provider.doubao_search_custom'))
            ->assertSee(__('admin.ai_source_providers.deepseek_config_title'))
            ->assertSee(__('admin.ai_source_providers.doubao_ark_config_title'))
            ->assertSee('test-search', false);
    }

    public function test_admin_can_create_doubao_search_custom_provider(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.ai-source-providers.store'), [
                'name' => '豆包搜索 Custom',
                'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
                'api_key' => 'doubao-secret-key',
                'daily_limit' => 50,
                'count' => 3,
                'content_formats' => 'Markdown',
                'need_summary' => '1',
                'need_content' => '0',
                'need_url' => '1',
                'sites' => "geoflow.example\nexample.com",
                'block_hosts' => 'blocked.example',
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHas('message');

        $provider = AiSourceProvider::query()->firstOrFail();
        $this->assertSame(AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM, $provider->provider_key);
        $this->assertSame('https://open.feedcoopapi.com/search_api/web_search', $provider->endpoint_url);
        $this->assertSame(50, (int) $provider->daily_limit);
        $this->assertSame('doubao-secret-key', app(ApiKeyCrypto::class)->decrypt((string) $provider->getRawOriginal('api_key')));
        $this->assertSame(3, (int) $provider->metadata_json['count']);
        $this->assertTrue((bool) $provider->metadata_json['need_summary']);
        $this->assertFalse((bool) $provider->metadata_json['need_content']);
        $this->assertSame(['geoflow.example', 'example.com'], $provider->metadata_json['sites']);
    }

    public function test_admin_cannot_save_a_search_provider_on_an_untrusted_host(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.ai-source-providers.index'))
            ->post(route('admin.ai-source-providers.store'), [
                'name' => 'Untrusted Search',
                'endpoint_url' => 'https://attacker.example/feedcoopapi.com/search',
                'api_key' => 'must-not-be-sent',
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHasErrors();
        $this->assertDatabaseCount('ai_source_providers', 0);
        $this->assertNull(session()->getOldInput('api_key'));
    }

    public function test_admin_can_update_provider_without_rotating_api_key(): void
    {
        $provider = $this->createSearchProvider();

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->put(route('admin.ai-source-providers.update', ['providerId' => (int) $provider->id]), [
                'name' => 'Updated Doubao Search',
                'endpoint_url' => 'https://open.feedcoopapi.com/search_api/updated',
                'api_key' => '',
                'daily_limit' => 20,
                'count' => 5,
                'content_formats' => 'Text',
                'need_summary' => '0',
                'need_content' => '1',
                'need_url' => '1',
                'status' => 'inactive',
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHas('message');

        $provider->refresh();
        $this->assertSame('Updated Doubao Search', $provider->name);
        $this->assertSame('inactive', $provider->status);
        $this->assertSame(20, (int) $provider->daily_limit);
        $this->assertSame(5, (int) $provider->metadata_json['count']);
        $this->assertSame('Text', $provider->metadata_json['content_formats']);
        $this->assertSame('test-search-key', app(ApiKeyCrypto::class)->decrypt((string) $provider->getRawOriginal('api_key')));
    }

    public function test_provider_page_tolerates_missing_visibility_run_tables(): void
    {
        Schema::dropIfExists('ai_visibility_sources');
        Schema::dropIfExists('ai_visibility_runs');
        $provider = $this->createSearchProvider();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-source-providers.index'))
            ->assertOk()
            ->assertSee('test-search', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-source-providers.delete', ['providerId' => (int) $provider->id]))
            ->assertRedirect(route('admin.ai-source-providers.index'));

        $this->assertModelMissing($provider);
    }

    public function test_admin_can_bind_ark_and_deepseek_models(): void
    {
        $arkModel = $this->createAiModel([
            'name' => 'Ark Search',
            'model_id' => 'doubao-seed-2-0-lite-260428',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $deepSeekModel = $this->createAiModel([
            'name' => 'DeepSeek V4 Flash',
            'model_id' => 'deepseek-v4-flash',
            'api_url' => 'https://api.deepseek.com',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.ai-source-providers.model-bindings'), [
                'ark_model_id' => (int) $arkModel->id,
                'deepseek_model_id' => (int) $deepSeekModel->id,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHas('message');

        $this->assertSame(
            (string) $arkModel->id,
            (string) SiteSetting::query()->where('setting_key', 'ai_visibility_ark_model_id')->value('setting_value')
        );
        $this->assertSame(
            (string) $deepSeekModel->id,
            (string) SiteSetting::query()->where('setting_key', 'ai_visibility_deepseek_analysis_model_id')->value('setting_value')
        );
    }

    public function test_admin_can_save_deepseek_api_config_and_bind_it(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                'binding_type' => 'deepseek',
                'name' => 'DeepSeek GEO Analysis',
                'model_id' => 'deepseek-v4-flash',
                'api_url' => 'https://api.deepseek.com',
                'api_key' => 'deepseek-secret',
                'daily_limit' => 30,
                'max_tokens' => 4096,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHas('message');

        $model = AiModel::query()->where('model_id', 'deepseek-v4-flash')->firstOrFail();
        $this->assertSame('DeepSeek GEO Analysis', $model->name);
        $this->assertSame('chat', $model->model_type);
        $this->assertSame(30, (int) $model->daily_limit);
        $this->assertSame(4096, (int) $model->max_tokens);
        $this->assertSame('deepseek-secret', app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key')));
        $this->assertSame(
            (string) $model->id,
            (string) SiteSetting::query()->where('setting_key', 'ai_visibility_deepseek_analysis_model_id')->value('setting_value')
        );
    }

    public function test_admin_can_save_doubao_ark_api_config_and_bind_it(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                'binding_type' => 'ark',
                'name' => 'Doubao Ark Search',
                'model_id' => 'doubao-seed-2-0-lite-260428',
                'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'api_key' => 'ark-secret',
                'daily_limit' => 40,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHas('message');

        $model = AiModel::query()->where('model_id', 'doubao-seed-2-0-lite-260428')->firstOrFail();
        $this->assertSame('Doubao Ark Search', $model->name);
        $this->assertSame('chat', $model->model_type);
        $this->assertSame(40, (int) $model->daily_limit);
        $this->assertSame('ark-secret', app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key')));
        $this->assertSame(
            (string) $model->id,
            (string) SiteSetting::query()->where('setting_key', 'ai_visibility_ark_model_id')->value('setting_value')
        );
    }

    public function test_api_key_is_not_flashed_to_the_session_when_model_validation_fails(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.ai-source-providers.index'))
            ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                'binding_type' => 'deepseek',
                'name' => 'DeepSeek GEO Analysis',
                'model_id' => 'deepseek-v4-flash',
                'api_url' => 'not-a-valid-url',
                'api_key' => 'must-not-enter-session',
                'daily_limit' => 30,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHasErrors('api_url');
        $this->assertNull(session()->getOldInput('api_key'));
    }

    public function test_admin_cannot_repoint_a_saved_model_key_to_an_untrusted_host(): void
    {
        $model = $this->createAiModel([
            'name' => 'DeepSeek GEO Analysis',
            'model_id' => 'deepseek-v4-flash',
            'api_url' => 'https://api.deepseek.com',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'ai_visibility_deepseek_analysis_model_id',
            'setting_value' => (string) $model->id,
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.ai-source-providers.index'))
            ->post(route('admin.ai-source-providers.model-bindings.upsert-api'), [
                'binding_type' => 'deepseek',
                'name' => 'DeepSeek GEO Analysis',
                'model_id' => 'deepseek-v4-flash',
                'api_url' => 'https://api.deepseek.com.attacker.example/v1',
                'api_key' => '',
                'daily_limit' => 30,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHasErrors();
        $this->assertSame('https://api.deepseek.com', $model->fresh()->api_url);
    }

    public function test_admin_cannot_bind_non_ark_model_as_ark_search_model(): void
    {
        $model = $this->createAiModel([
            'name' => 'Generic Chat',
            'model_id' => 'gpt-4o',
            'api_url' => 'https://api.openai.com',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.ai-source-providers.index'))
            ->post(route('admin.ai-source-providers.model-bindings'), [
                'ark_model_id' => (int) $model->id,
                'deepseek_model_id' => 0,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHasErrors();
    }

    public function test_admin_cannot_bind_a_model_without_an_api_key(): void
    {
        $model = $this->createAiModel([
            'name' => 'DeepSeek Missing Key',
            'model_id' => 'deepseek-v4-flash',
            'api_url' => 'https://api.deepseek.com',
            'api_key' => '',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.ai-source-providers.index'))
            ->post(route('admin.ai-source-providers.model-bindings'), [
                'ark_model_id' => 0,
                'deepseek_model_id' => (int) $model->id,
            ]);

        $response->assertRedirect(route('admin.ai-source-providers.index'))
            ->assertSessionHasErrors();
        $this->assertNull(
            SiteSetting::query()
                ->where('setting_key', 'ai_visibility_deepseek_analysis_model_id')
                ->value('setting_value')
        );
    }

    public function test_admin_search_provider_test_counts_against_daily_usage(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_test',
                'Result' => [
                    'WebResults' => [
                        [
                            'Title' => 'GEOFlow Source',
                            'Url' => 'https://example.com/geoflow',
                            'Snippet' => 'GEOFlow source result',
                            'Summary' => 'GEOFlow summary',
                        ],
                    ],
                ],
            ]),
        ]);

        $provider = $this->createSearchProvider([
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'metadata_json' => [
                'count' => 3,
                'search_type' => 'web',
                'need_summary' => true,
                'need_content' => false,
                'need_url' => true,
                'content_formats' => 'Markdown',
                'sites' => ['geoflow.example'],
                'block_hosts' => ['blocked.example'],
            ],
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => (int) $provider->id]), [
                'query' => 'GEOFlow',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.source_count', 1)
            ->assertJsonPath('meta.sources.0.domain', 'example.com');

        $provider->refresh();
        $this->assertSame(1, (int) $provider->used_today);
        $this->assertSame(1, (int) $provider->total_used);
        $this->assertSame(now()->toDateString(), $provider->usage_date?->toDateString());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.feedcoopapi.com/search_api/web_search'
            && $request->hasHeader('Authorization', 'Bearer test-search-key')
            && $request['Query'] === 'GEOFlow'
            && $request['Count'] === 3
            && ($request['NeedSummary'] ?? null) === true
            && ($request['Filter']['NeedContent'] ?? null) === false
            && ($request['Filter']['NeedUrl'] ?? null) === true
            && ($request['Filter']['Sites'] ?? null) === ['geoflow.example']
            && ($request['Filter']['BlockHosts'] ?? null) === ['blocked.example']);
    }

    public function test_failed_search_provider_test_attempt_consumes_daily_quota(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response('Provider unavailable', 503),
        ]);

        $provider = $this->createSearchProvider(['daily_limit' => 1]);
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => (int) $provider->id]))
            ->assertUnprocessable();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => (int) $provider->id]))
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => __('admin.ai_source_providers.test_failed', [
                'message' => '搜索源已达到每日调用上限',
            ])]);

        $provider->refresh();
        $this->assertSame(1, (int) $provider->used_today);
        $this->assertSame(0, (int) $provider->total_used);
        Http::assertSentCount(2);
    }

    public function test_admin_can_test_deepseek_structured_output(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.deepseek.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'keyword' => 'GEOFlow',
                                'intent' => 'ai_visibility_analysis',
                                'source_actions' => ['publish docs', 'refresh citations'],
                                'confidence' => 0.91,
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $model = $this->createAiModel([
            'name' => 'DeepSeek Structured',
            'model_id' => 'deepseek-v4-flash',
            'api_url' => 'https://api.deepseek.com',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-source-providers.model-bindings.test'), [
                'binding_type' => 'deepseek',
                'model_id' => (int) $model->id,
                'query' => 'GEOFlow',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.provider_key', 'deepseek')
            ->assertJsonPath('meta.structured_output.keyword', 'GEOFlow')
            ->assertJsonPath('meta.structured_output.source_actions.0', 'publish docs');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.deepseek.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-model-key')
            && ($request['response_format']['type'] ?? null) === 'json_object'
            && $request['model'] === 'deepseek-v4-flash');

        $model->refresh();
        $this->assertSame(1, (int) $model->used_today);
        $this->assertSame(1, (int) $model->total_used);
        $this->assertSame(now()->toDateString(), $model->usage_date?->toDateString());
    }

    public function test_failed_model_binding_test_attempt_consumes_daily_quota(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.deepseek.com/v1/chat/completions' => Http::response('Provider unavailable', 503),
        ]);

        $model = $this->createAiModel([
            'name' => 'DeepSeek Quota Probe',
            'model_id' => 'deepseek-v4-flash',
            'api_url' => 'https://api.deepseek.com',
            'daily_limit' => 1,
        ]);
        $admin = $this->createAdmin();

        $payload = [
            'binding_type' => 'deepseek',
            'model_id' => (int) $model->id,
            'query' => 'GEOFlow',
        ];

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-source-providers.model-bindings.test'), $payload)
            ->assertUnprocessable();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-source-providers.model-bindings.test'), $payload)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => __('admin.ai_source_providers.test_failed', [
                'message' => '模型已达到每日调用上限',
            ])]);

        $model->refresh();
        $this->assertSame(1, (int) $model->used_today);
        $this->assertSame(0, (int) $model->total_used);
        Http::assertSentCount(2);
    }

    public function test_admin_can_test_doubao_ark_structured_output(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'output_text' => json_encode([
                    'keyword' => 'GEOFlow',
                    'intent' => 'search_visibility',
                    'source_actions' => ['enable web search', 'collect source URLs'],
                    'confidence' => 0.88,
                ]),
            ]),
        ]);

        $model = $this->createAiModel([
            'name' => 'Doubao Ark Structured',
            'model_id' => 'doubao-seed-2-0-lite-260428',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-source-providers.model-bindings.test'), [
                'binding_type' => 'ark',
                'model_id' => (int) $model->id,
                'query' => 'GEOFlow',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.provider_key', 'doubao_ark')
            ->assertJsonPath('meta.structured_output.keyword', 'GEOFlow')
            ->assertJsonPath('meta.structured_output.source_actions.0', 'enable web search');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/responses'
            && $request->hasHeader('Authorization', 'Bearer test-model-key')
            && ($request['text']['format']['type'] ?? null) === 'json_schema'
            && ($request['text']['format']['strict'] ?? null) === true
            && $request['model'] === 'doubao-seed-2-0-lite-260428');
    }

    public function test_doubao_ark_structured_test_accepts_full_responses_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'output_text' => json_encode([
                    'keyword' => 'GEOFlow',
                    'intent' => 'endpoint_check',
                    'source_actions' => ['keep endpoint stable'],
                    'confidence' => 0.82,
                ]),
            ]),
        ]);

        $model = $this->createAiModel([
            'name' => 'Doubao Ark Full Endpoint',
            'model_id' => 'doubao-seed-2-0-lite-260428',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3/responses',
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-source-providers.model-bindings.test'), [
                'binding_type' => 'ark',
                'model_id' => (int) $model->id,
            ])
            ->assertOk()
            ->assertJsonPath('meta.structured_output.intent', 'endpoint_check');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/responses');
    }

    public function test_provider_connection_test_validates_query_length_before_calling_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $provider = $this->createSearchProvider();

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-source-providers.test', ['providerId' => (int) $provider->id]), [
                'query' => str_repeat('a', 201),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');

        Http::assertNothingSent();
    }

    public function test_provider_connection_test_is_rate_limited(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_rate_limit',
                'Result' => ['WebResults' => []],
            ]),
        ]);

        $provider = $this->createSearchProvider();
        $this->actingAs($this->createAdmin(), 'admin');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('admin.ai-source-providers.test', ['providerId' => (int) $provider->id]))
                ->assertOk();
        }

        $this->postJson(route('admin.ai-source-providers.test', ['providerId' => (int) $provider->id]))
            ->assertTooManyRequests();
        Http::assertSentCount(5);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'ai_source_admin',
            'password' => 'secret-123',
            'email' => 'ai-source-admin@example.com',
            'display_name' => 'AI Source Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createSearchProvider(array $overrides = []): AiSourceProvider
    {
        return AiSourceProvider::query()->create(array_merge([
            'name' => 'test-search',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-search-key'),
            'status' => 'active',
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'metadata_json' => [
                'count' => 10,
                'search_type' => 'web',
                'need_summary' => true,
                'need_content' => true,
                'need_url' => true,
                'content_formats' => 'Markdown',
                'sites' => [],
                'block_hosts' => [],
            ],
        ], $overrides));
    }

    private function createAiModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-model-key'),
            'model_id' => 'test-chat-model',
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
