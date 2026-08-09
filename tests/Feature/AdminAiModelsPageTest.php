<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAiModelsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_test_chat_model_connection(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OK']],
                ],
            ]),
        ]);

        $model = $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'chat')
            ->assertJsonPath('meta.http_status', 200);

        $this->assertSame(now()->toDateString(), $model->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request['model'] === 'test-chat-model'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_admin_can_test_atlas_cloud_chat_model_connection(): void
    {
        Http::fake([
            'https://api.atlascloud.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OK']],
                ],
            ]),
        ]);

        $model = $this->createAiModel('chat', [
            'name' => 'Atlas Cloud DeepSeek V4 Pro',
            'model_id' => 'deepseek-ai/deepseek-v4-pro',
            'api_url' => 'https://api.atlascloud.ai/v1',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'chat')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.atlascloud.ai/v1/chat/completions'
            && $request['model'] === 'deepseek-ai/deepseek-v4-pro'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_official_openai_connection_test_uses_the_same_responses_api_as_runtime(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test',
                'object' => 'response',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'OK'],
                        ],
                    ],
                ],
            ]),
        ]);

        $model = $this->createAiModel('chat', [
            'model_id' => 'gpt-5.6-terra',
            'api_url' => 'https://api.openai.com',
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && ($request['model'] ?? null) === 'gpt-5.6-terra'
            && ($request['input'] ?? null) === 'Reply with OK.'
            && ! array_key_exists('messages', (array) $request->data()));
    }

    public function test_model_connection_tests_are_rate_limited(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OK']],
                ],
            ]),
        ]);

        $admin = $this->createAdmin();
        $model = $this->createAiModel('chat');

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($admin, 'admin')
                ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
                ->assertOk();
        }

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertTooManyRequests();
    }

    public function test_model_connection_test_stops_after_daily_quota_is_used(): void
    {
        Http::fake();
        $model = $this->createAiModel('chat', [
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('admin.ai_models.test_error_daily_limit'));

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        Http::assertNothingSent();
    }

    public function test_admin_models_page_shows_test_action(): void
    {
        $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_models.test'));
    }

    public function test_admin_models_page_resets_usage_display_after_the_usage_date_changes(): void
    {
        $this->travelTo('2026-07-27 09:00:00');
        $model = $this->createAiModel('chat', [
            'used_today' => 9,
            'usage_date' => '2026-07-26',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $modelRow = collect($response->viewData('models'))
            ->firstWhere('id', (int) $model->id);

        $response->assertOk();
        $this->assertSame(0, (int) ($modelRow['used_today'] ?? -1));
    }

    public function test_admin_models_page_works_before_max_tokens_migration_runs(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropColumn('max_tokens');
        });

        $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_models.list_title'));
    }

    public function test_admin_saves_max_tokens_only_for_chat_models(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), [
                'name' => 'Long Form Chat',
                'version' => 'test',
                'api_key' => 'test-api-key',
                'model_id' => 'long-chat',
                'model_type' => 'chat',
                'api_url' => 'https://ai.test',
                'failover_priority' => 100,
                'daily_limit' => 0,
                'max_tokens' => 12000,
            ])
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSame(12000, (int) AiModel::query()->where('model_id', 'long-chat')->value('max_tokens'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), [
                'name' => 'Embedding Model',
                'version' => 'test',
                'api_key' => 'test-api-key',
                'model_id' => 'embedding-model',
                'model_type' => 'embedding',
                'api_url' => 'https://ai.test',
                'failover_priority' => 100,
                'daily_limit' => 0,
                'max_tokens' => 12000,
            ])
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertNull(AiModel::query()->where('model_id', 'embedding-model')->value('max_tokens'));
    }

    public function test_admin_must_rotate_api_key_when_model_origin_changes(): void
    {
        $model = $this->createAiModel('chat', [
            'api_url' => 'https://api.openai.com',
        ]);
        $originalEncryptedKey = (string) $model->getRawOriginal('api_key');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.ai-models.index'))
            ->put(route('admin.ai-models.update', ['modelId' => (int) $model->id]), [
                'name' => 'Atlas Cloud DeepSeek V4 Pro',
                'version' => 'v4',
                'api_key' => '',
                'model_id' => 'deepseek-ai/deepseek-v4-pro',
                'model_type' => 'chat',
                'api_url' => 'https://api.atlascloud.ai/v1',
                'failover_priority' => 100,
                'daily_limit' => 0,
                'status' => 'active',
            ]);

        $response
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHasErrors('api_key');

        $model->refresh();
        $this->assertSame('https://api.openai.com', $model->api_url);
        $this->assertSame($originalEncryptedKey, (string) $model->getRawOriginal('api_key'));
    }

    public function test_admin_can_keep_api_key_when_only_model_endpoint_path_changes(): void
    {
        $model = $this->createAiModel('chat', [
            'api_url' => 'https://api.openai.com',
        ]);
        $originalEncryptedKey = (string) $model->getRawOriginal('api_key');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => (int) $model->id]), [
                'name' => 'OpenAI Chat',
                'version' => '',
                'api_key' => '',
                'model_id' => 'gpt-4o',
                'model_type' => 'chat',
                'api_url' => 'https://api.openai.com/v1',
                'failover_priority' => 100,
                'daily_limit' => 0,
                'status' => 'active',
            ]);

        $response
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHasNoErrors();

        $model->refresh();
        $this->assertSame('https://api.openai.com/v1', $model->api_url);
        $this->assertSame($originalEncryptedKey, (string) $model->getRawOriginal('api_key'));
    }

    public function test_admin_can_rotate_api_key_when_model_origin_changes(): void
    {
        $model = $this->createAiModel('chat', [
            'api_url' => 'https://api.openai.com',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => (int) $model->id]), [
                'name' => 'Atlas Cloud DeepSeek V4 Pro',
                'version' => 'v4',
                'api_key' => 'atlas-cloud-api-key',
                'model_id' => 'deepseek-ai/deepseek-v4-pro',
                'model_type' => 'chat',
                'api_url' => 'https://api.atlascloud.ai/v1',
                'failover_priority' => 100,
                'daily_limit' => 0,
                'status' => 'active',
            ]);

        $response
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHasNoErrors();

        $model->refresh();
        $this->assertSame('https://api.atlascloud.ai/v1', $model->api_url);
        $this->assertSame(
            'atlas-cloud-api-key',
            app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key'))
        );
    }

    public function test_admin_can_test_embedding_model_connection(): void
    {
        Http::fake([
            'https://ai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $model = $this->createAiModel('embedding');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'embedding')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/embeddings'
            && $request['model'] === 'test-embedding-model'
            && $request['input'] === 'GEOFlow embedding connection test');
    }

    public function test_admin_can_test_volcengine_embedding_model_connection(): void
    {
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.11, 0.22, 0.33]],
                ],
            ]),
        ]);

        $model = $this->createAiModel('embedding', [
            'name' => 'Doubao Embedding',
            'model_id' => 'doubao-embedding-text-240515',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'embedding')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/embeddings'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['model'] === 'doubao-embedding-text-240515'
            && $request['input'] === 'GEOFlow embedding connection test');
    }

    public function test_admin_can_test_gemini_chat_model_connection(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'OK'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $model = $this->createAiModel('chat', [
            'name' => 'Gemini 3 Flash Preview',
            'model_id' => 'gemini-3-flash-preview',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'chat')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && ($request['contents'][0]['parts'][0]['text'] ?? '') === 'Reply with OK.'
            && ($request['generationConfig']['thinkingConfig']['thinkingLevel'] ?? '') === 'minimal'
            && ($request['generationConfig']['maxOutputTokens'] ?? 0) >= 64);
    }

    public function test_admin_can_test_gemini_embedding_model_connection_with_retrieval_prefix(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents' => Http::response([
                'embeddings' => [
                    ['values' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $model = $this->createAiModel('embedding', [
            'name' => 'Gemini Embedding 2',
            'model_id' => 'gemini-embedding-2',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'embedding')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && ($request['requests'][0]['content']['parts'][0]['text'] ?? '') === 'task: search result | query: GEOFlow embedding connection test'
            && ! isset($request['requests'][0]['taskType'])
            && ! isset($request['taskType']));
    }

    public function test_gemini_three_pro_connection_test_uses_low_thinking_level(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'OK'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $model = $this->createAiModel('chat', [
            'name' => 'Gemini 3 Pro Preview',
            'model_id' => 'gemini-3-pro-preview',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent'
            && ($request['generationConfig']['thinkingConfig']['thinkingLevel'] ?? '') === 'low'
            && ($request['generationConfig']['maxOutputTokens'] ?? 0) >= 64);
    }

    public function test_gemini_three_six_connection_test_omits_deprecated_sampling_parameters(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'OK']]]],
                ],
            ]),
        ]);

        $model = $this->createAiModel('chat', [
            'name' => 'Gemini 3.6 Flash',
            'model_id' => 'gemini-3.6-flash',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent'
            && ($request['generationConfig']['thinkingConfig']['thinkingLevel'] ?? '') === 'low'
            && ! array_key_exists('temperature', (array) ($request['generationConfig'] ?? []))
            && ! array_key_exists('topP', (array) ($request['generationConfig'] ?? []))
            && ! array_key_exists('topK', (array) ($request['generationConfig'] ?? [])));
    }

    public function test_admin_models_page_shows_embedding_quick_fill_presets_and_notice(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee('MiniMax-M3', false)
            ->assertSee('MiniMax M2.7', false)
            ->assertSee('MiniMax-M2.7-highspeed', false)
            ->assertSee('deepseek-v4-flash', false)
            ->assertSee('DeepSeek V4 Pro', false)
            ->assertSee('Atlas Cloud', false)
            ->assertSee('deepseek-ai/deepseek-v4-pro', false)
            ->assertSee('https://api.atlascloud.ai/v1', false)
            ->assertSee('gpt-5.6-terra', false)
            ->assertSee('gemini-3.6-flash', false)
            ->assertSee('GLM-5.2', false)
            ->assertSee('Gemini', false)
            ->assertSee('Gemini Embedding', false)
            ->assertSee('Doubao Embedding', false)
            ->assertSee('doubao-embedding-text-240515', false)
            ->assertSee(__('admin.ai_models.gemini_embedding_notice'));
    }

    public function test_admin_can_update_knowledge_chunking_config(): void
    {
        $model = $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.ai-models.chunking-config'), [
                'knowledge_chunk_strategy' => 'semantic_llm',
                'knowledge_chunking_model_id' => (int) $model->id,
            ]);

        $response->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHas('message');

        $this->assertSame(
            'semantic_llm',
            (string) SiteSetting::query()->where('setting_key', 'knowledge_chunk_strategy')->value('setting_value')
        );
        $this->assertSame(
            (string) $model->id,
            (string) SiteSetting::query()->where('setting_key', 'knowledge_chunking_model_id')->value('setting_value')
        );
    }

    public function test_admin_models_page_shows_knowledge_chunking_config(): void
    {
        $model = $this->createAiModel('chat', ['name' => 'Gemini 3.1 Flash Lite']);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_models.chunking_title'))
            ->assertSee(__('admin.ai_models.chunk_strategy_semantic'))
            ->assertSee('Gemini 3.1 Flash Lite');
    }

    public function test_model_connection_test_reports_provider_errors(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(['detail' => 'API Key invalid'], 401),
        ]);

        $model = $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.http_status', 401);

        $this->assertStringContainsString('API Key invalid', (string) $response->json('message'));
        $this->assertStringNotContainsString('test-api-key', (string) $response->json('message'));

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_inactive_model_connection_test_still_enforces_daily_quota(): void
    {
        Http::fake();
        $model = $this->createAiModel('chat', [
            'status' => 'inactive',
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', __('admin.ai_models.test_error_daily_limit'));

        Http::assertNothingSent();
    }

    public function test_failed_inactive_model_connection_attempt_consumes_daily_quota(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(['detail' => 'Provider unavailable'], 503),
        ]);
        $model = $this->createAiModel('chat', [
            'status' => 'inactive',
            'daily_limit' => 1,
        ]);
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('meta.http_status', 503);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', __('admin.ai_models.test_error_daily_limit'));

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        Http::assertSentCount(1);
    }

    public function test_model_connection_test_extracts_provider_errors_from_sse_responses(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(
                'data: {"error":{"message":"This account only permits approved clients."}}'."\n\n",
                403,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $model = $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response->assertUnprocessable()->assertJsonPath('success', false);
        $this->assertStringContainsString('This account only permits approved clients.', (string) $response->json('message'));
    }

    public function test_inactive_model_can_be_tested_before_it_is_reenabled(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->chatCompletion('OK')),
        ]);

        $model = $this->createAiModel('chat', ['status' => 'inactive']);

        $this->actingAs($this->createAdmin(), 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    /** @return array<string, mixed> */
    private function chatCompletion(string $content): array
    {
        return [
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ];
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'ai_model_admin',
            'password' => 'secret-123',
            'email' => 'ai-model-admin@example.com',
            'display_name' => 'AI Model Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createAiModel(string $type, array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => $type === 'embedding' ? 'Test Embedding' : 'Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => $type === 'embedding' ? 'test-embedding-model' : 'test-chat-model',
            'model_type' => $type,
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
