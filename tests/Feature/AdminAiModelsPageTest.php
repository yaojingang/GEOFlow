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

    public function test_admin_models_page_shows_embedding_quick_fill_presets_and_notice(): void
    {
        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee('MiniMax-M3', false)
            ->assertSee('MiniMax M2.7', false)
            ->assertSee('MiniMax-M2.7-highspeed', false)
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
