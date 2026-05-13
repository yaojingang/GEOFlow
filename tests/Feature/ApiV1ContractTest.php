<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API v1 契约：鉴权、scope、登录与统一信封（SQLite 测试库依赖 {@see 2026_04_18_120002_sqlite_geoflow_minimal_for_testing}）。
 */
class ApiV1ContractTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveAdmin(string $username = 'api_test_admin', string $password = 'secret-123'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => $password,
            'email' => 't@example.com',
            'display_name' => 'API Test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{plain: string}
     */
    private function createBearerToken(Admin $admin, array $scopes): array
    {
        $plain = $admin->createToken('contract-test', $scopes)->plainTextToken;

        return ['plain' => $plain];
    }

    public function test_catalog_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/catalog')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_login_validation_empty_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_error_response_includes_request_id_meta(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['meta' => ['request_id', 'timestamp']]);
    }

    public function test_login_invalid_credentials_returns_401(): void
    {
        $this->createActiveAdmin('u1', 'right-pass');

        $this->postJson('/api/v1/auth/login', [
            'username' => 'u1',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_login_success_returns_token_and_admin_summary(): void
    {
        $this->createActiveAdmin('u2', 'good-pass');

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'u2',
            'password' => 'good-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['token', 'scopes', 'expires_at', 'admin' => ['id', 'username', 'display_name', 'role', 'status']],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.expires_at'));
        $this->assertContains('materials:read', $response->json('data.scopes'));
        $this->assertContains('materials:write', $response->json('data.scopes'));
    }

    public function test_login_locks_account_after_repeated_password_failures(): void
    {
        $admin = $this->createActiveAdmin('lock_me', 'right-pass');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lock_me',
                'password' => 'wrong-pass',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'username' => 'lock_me',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_locked');

        $this->assertSame('locked', $admin->fresh()->status);
    }

    public function test_catalog_forbidden_when_scope_missing(): void
    {
        $admin = $this->createActiveAdmin('u3', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_catalog_success_envelope_with_catalog_read_scope(): void
    {
        $admin = $this->createActiveAdmin('u4', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'models',
                    'prompts',
                    'keyword_libraries',
                    'title_libraries',
                    'image_libraries',
                    'knowledge_bases',
                    'authors',
                    'categories',
                ],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }

    public function test_materials_require_materials_scope(): void
    {
        $admin = $this->createActiveAdmin('u5', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_keyword_library_material_crud_and_items(): void
    {
        $admin = $this->createActiveAdmin('u6', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);

        $create = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/materials/keyword-libraries', [
                'name' => 'API Keywords',
                'description' => 'Created from API',
            ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'keyword-libraries')
            ->assertJsonPath('data.item.name', 'API Keywords');

        $libraryId = (int) $create->json('data.item.id');

        $item = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/materials/keyword-libraries/{$libraryId}/items", [
                'keyword' => 'geo automation',
            ]);

        $item->assertCreated()
            ->assertJsonPath('data.parent_id', $libraryId)
            ->assertJsonPath('data.item.keyword', 'geo automation');

        $this->assertDatabaseHas('keyword_libraries', ['id' => $libraryId, 'keyword_count' => 1]);
        $this->assertDatabaseHas('keywords', ['library_id' => $libraryId, 'keyword' => 'geo automation']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials/keyword-libraries')
            ->assertOk()
            ->assertJsonPath('data.type', 'keyword-libraries')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_delete_material_items_refreshes_counts(): void
    {
        $admin = $this->createActiveAdmin('u7', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);
        $library = KeywordLibrary::query()->create([
            'name' => 'Delete Items',
            'description' => '',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'delete me',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/materials/keyword-libraries/{$library->id}/items", [
                'ids' => [$keyword->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertDatabaseMissing('keywords', ['id' => $keyword->id]);
        $this->assertDatabaseHas('keyword_libraries', ['id' => $library->id, 'keyword_count' => 0]);
    }

    public function test_task_delete_api_removes_task(): void
    {
        $admin = $this->createActiveAdmin('u8', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $task = Task::query()->create([
            'name' => 'API delete task',
            'status' => 'paused',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', $task->id);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_task_create_accepts_omitted_optional_material_fields(): void
    {
        $admin = $this->createActiveAdmin('u9', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $model = AiModel::query()->create([
            'name' => 'Task Create Model',
            'model_id' => 'task-create-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Task Create Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Task Create Titles',
            'description' => '',
            'title_count' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/tasks', [
                'name' => 'API create task with optional fields omitted',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
                'status' => 'paused',
                'category_mode' => 'smart',
                'draft_limit' => 1,
                'article_limit' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'API create task with optional fields omitted')
            ->assertJsonPath('data.image_library_id', null)
            ->assertJsonPath('data.author_id', null)
            ->assertJsonPath('data.knowledge_base_id', null)
            ->assertJsonPath('data.fixed_category_id', null);

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'image_library_id' => null,
            'author_id' => null,
            'knowledge_base_id' => null,
            'fixed_category_id' => null,
        ]);
    }
}
