<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request['model'] === 'test-chat-model'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_admin_models_page_shows_test_action(): void
    {
        $this->createAiModel('chat');

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_models.test'));
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

    public function test_admin_models_page_shows_gemini_quick_fill_and_embedding_notice(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee('Gemini', false)
            ->assertSee('Gemini Embedding', false)
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

    public function test_admin_models_page_shows_semantic_chunking_controls_and_badge(): void
    {
        $model = $this->createAiModel('chat', ['name' => 'Semantic Chat']);
        SiteSetting::query()->create([
            'setting_key' => 'semantic_chunking_chat_model_ids',
            'setting_value' => json_encode([(int) $model->id]),
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_models.semantic_chunking_label'))
            ->assertSee(__('admin.ai_models.semantic_chunking_badge'));
    }

    public function test_admin_can_enable_semantic_chunking_for_chat_model_without_schema_change(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.ai-models.store'), [
                'name' => 'Semantic Chat',
                'version' => 'test',
                'api_key' => 'test-api-key',
                'model_id' => 'test-chat-model',
                'model_type' => 'chat',
                'api_url' => 'https://ai.test',
                'failover_priority' => 5,
                'daily_limit' => 0,
                'semantic_chunking_enabled' => '1',
            ]);

        $response->assertRedirect(route('admin.ai-models.index'));

        $model = AiModel::query()->where('name', 'Semantic Chat')->firstOrFail();
        $storedIds = json_decode((string) SiteSetting::query()
            ->where('setting_key', 'semantic_chunking_chat_model_ids')
            ->value('setting_value'), true);

        $this->assertSame([(int) $model->id], $storedIds);
    }

    public function test_semantic_chunking_capability_is_removed_when_model_becomes_unavailable(): void
    {
        $model = $this->createAiModel('chat', ['name' => 'Semantic Chat']);
        SiteSetting::query()->create([
            'setting_key' => 'semantic_chunking_chat_model_ids',
            'setting_value' => json_encode([(int) $model->id]),
        ]);

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => (int) $model->id]), [
                'name' => 'Semantic Chat',
                'version' => 'test',
                'api_key' => '',
                'model_id' => 'test-chat-model',
                'model_type' => 'chat',
                'api_url' => 'https://ai.test',
                'failover_priority' => 5,
                'daily_limit' => 0,
                'status' => 'inactive',
                'semantic_chunking_enabled' => '1',
            ]);

        $response->assertRedirect(route('admin.ai-models.index'));

        $storedIds = json_decode((string) SiteSetting::query()
            ->where('setting_key', 'semantic_chunking_chat_model_ids')
            ->value('setting_value'), true);

        $this->assertSame([], $storedIds);
    }

    public function test_semantic_chunking_capability_is_removed_when_unchecked_changed_to_embedding_or_deleted(): void
    {
        $admin = $this->createAdmin();
        $model = $this->createAiModel('chat', ['name' => 'Semantic Chat']);
        SiteSetting::query()->create([
            'setting_key' => 'semantic_chunking_chat_model_ids',
            'setting_value' => json_encode([(int) $model->id]),
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => (int) $model->id]), [
                'name' => 'Semantic Chat',
                'version' => 'test',
                'api_key' => '',
                'model_id' => 'test-chat-model',
                'model_type' => 'chat',
                'api_url' => 'https://ai.test',
                'failover_priority' => 5,
                'daily_limit' => 0,
                'status' => 'active',
                'semantic_chunking_enabled' => '0',
            ])
            ->assertRedirect(route('admin.ai-models.index'));
        $this->assertSame([], $this->semanticChunkingModelIds());

        SiteSetting::query()
            ->where('setting_key', 'semantic_chunking_chat_model_ids')
            ->update(['setting_value' => json_encode([(int) $model->id])]);
        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => (int) $model->id]), [
                'name' => 'Semantic Chat',
                'version' => 'test',
                'api_key' => '',
                'model_id' => 'test-embedding-model',
                'model_type' => 'embedding',
                'api_url' => 'https://ai.test/v1/embeddings',
                'failover_priority' => 5,
                'daily_limit' => 0,
                'status' => 'active',
                'semantic_chunking_enabled' => '1',
            ])
            ->assertRedirect(route('admin.ai-models.index'));
        $this->assertSame([], $this->semanticChunkingModelIds());

        SiteSetting::query()
            ->where('setting_key', 'semantic_chunking_chat_model_ids')
            ->update(['setting_value' => json_encode([(int) $model->id])]);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.delete', ['modelId' => (int) $model->id]))
            ->assertRedirect(route('admin.ai-models.index'));

        $this->assertSame([], $this->semanticChunkingModelIds());
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

    /**
     * @return list<int>
     */
    private function semanticChunkingModelIds(): array
    {
        $value = SiteSetting::query()
            ->where('setting_key', 'semantic_chunking_chat_model_ids')
            ->value('setting_value');

        return json_decode((string) $value, true) ?: [];
    }
}
