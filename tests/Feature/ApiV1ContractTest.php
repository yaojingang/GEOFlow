<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\GeoFlow\TaskRealtimeBroadcastService;
use App\Services\GeoFlow\TaskTitleReadinessService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
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

    public function test_article_quality_status_uses_read_scope_and_a_lightweight_contract(): void
    {
        $admin = $this->createActiveAdmin('quality_status_api_admin', 'p');
        $category = Category::query()->create(['name' => 'Quality API category', 'slug' => 'quality-api-category']);
        $author = Author::query()->create(['name' => 'Quality API author']);
        $article = Article::query()->create([
            'title' => 'Quality status article',
            'slug' => 'quality-status-article',
            'content' => 'Safe content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $writeOnly = $this->createBearerToken($admin, ['articles:write']);
        $this->withHeader('Authorization', 'Bearer '.$writeOnly['plain'])
            ->getJson("/api/v1/articles/{$article->id}/ai-quality/status")
            ->assertForbidden();

        $read = $this->createBearerToken($admin, ['articles:read']);
        $this->withHeader('Authorization', 'Bearer '.$read['plain'])
            ->getJson("/api/v1/articles/{$article->id}/ai-quality/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.phase', 'not_started')
            ->assertJsonPath('data.elapsed_ms', 0)
            ->assertJsonMissingPath('data.article');
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

    public function test_login_reads_the_admin_inside_the_token_issuance_transaction(): void
    {
        $this->createActiveAdmin('transactional_login', 'right-pass');
        $transactionLevels = [];

        DB::listen(function (QueryExecuted $query) use (&$transactionLevels): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select')
                && str_contains($sql, 'admins')
                && str_contains($sql, 'username')) {
                $transactionLevels[] = DB::transactionLevel();
            }
        });

        $this->postJson('/api/v1/auth/login', [
            'username' => 'transactional_login',
            'password' => 'right-pass',
        ])->assertOk();

        $this->assertNotEmpty($transactionLevels);
        $this->assertNotContains(0, $transactionLevels);
    }

    public function test_login_temporarily_limits_username_and_ip_after_repeated_password_failures(): void
    {
        $this->travelTo(now()->startOfMinute());

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
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_attempts')
            ->assertJsonPath('error.details.retry_after', 900);

        $this->assertSame('active', $admin->fresh()->status);
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

    public function test_token_is_rejected_without_being_touched_when_its_owner_is_inactive(): void
    {
        $admin = $this->createActiveAdmin('inactive_token_owner', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);
        $admin->forceFill(['status' => 'inactive'])->save();

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Admin::class,
            'tokenable_id' => $admin->id,
            'last_used_at' => null,
        ]);
    }

    public function test_token_is_rejected_without_audit_fallback_when_its_owner_is_deleted(): void
    {
        $admin = $this->createActiveAdmin('deleted_token_owner', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);
        $tokenId = (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', $admin->id)
            ->value('id');

        DB::table('admins')->where('id', $admin->id)->delete();

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $tokenId,
            'last_used_at' => null,
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

    public function test_knowledge_base_list_bounds_content_while_detail_returns_the_full_body(): void
    {
        $admin = $this->createActiveAdmin('knowledge_list_reader', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read']);
        $content = str_repeat('知识库正文', 1200);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Large API Knowledge Base',
            'description' => '',
            'content' => $content,
            'file_type' => 'markdown',
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen($content, 'UTF-8'),
        ]);

        $list = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials/knowledge-bases?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.items.0.content_truncated', true);
        $this->assertSame(4000, mb_strlen((string) $list->json('data.items.0.content'), 'UTF-8'));

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials/knowledge-bases/'.(int) $knowledgeBase->id)
            ->assertOk()
            ->assertJsonPath('data.item.content', $content)
            ->assertJsonPath('data.item.content_truncated', false);
    }

    public function test_knowledge_base_api_create_survives_queue_publish_failure(): void
    {
        $admin = $this->createActiveAdmin('knowledge_queue_writer', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:write']);
        $this->mock(KnowledgeChunkSyncCoordinator::class, function ($mock): void {
            $mock->shouldReceive('request')
                ->once()
                ->andThrow(new \RuntimeException('queue unavailable'));
        });

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/materials/knowledge-bases', [
                'name' => 'Queue Failure API Knowledge',
                'description' => '',
                'content' => '正文已经保存，等待队列恢复。',
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.name', 'Queue Failure API Knowledge');

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'Queue Failure API Knowledge',
            'content' => '正文已经保存，等待队列恢复。',
        ]);
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

    public function test_keyword_and_title_item_inputs_reject_null_bytes_and_nfkc_expansion(): void
    {
        $admin = $this->createActiveAdmin('material_policy_writer', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:write']);
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => 'Policy Keywords',
            'description' => '',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Policy Titles',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", [
                'keyword' => "boundary\0null",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.keyword', '关键词不能包含 NUL 字符');

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => '有效标题',
                'keyword' => "boundary\0null",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.keyword', '关联关键词不能包含 NUL 字符');

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => str_repeat('ﬃ', 500),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.title', '标题长度不能超过 500 个字符');

        $this->assertDatabaseCount('keywords', 0);
        $this->assertDatabaseCount('titles', 0);
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
        $bearer = $this->createBearerToken($admin, ['tasks:read', 'tasks:write']);
        $task = Task::query()->create([
            'name' => 'API delete task',
            'status' => 'paused',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', $task->id);

        $this->assertNull(Task::query()->find($task->id));
        $this->assertNotNull(Task::onlyTrashed()->find($task->id));

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertNotFound();
    }

    public function test_regular_admin_api_cannot_manage_a_hosted_site_task(): void
    {
        $admin = $this->createActiveAdmin('hosted_delete_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $channel = DistributionChannel::query()->create([
            'name' => 'Protected hosted task channel',
            'domain' => 'protected-hosted-task.test',
            'endpoint_url' => 'https://protected-hosted-task.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $task = Task::query()->create([
            'name' => 'Protected hosted API task',
            'status' => 'paused',
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($channel->id);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->patchJson("/api/v1/tasks/{$task->id}", ['name' => 'Unauthorized update'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/start")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/stop")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/enqueue")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertNotNull(Task::query()->find($task->id));
        $this->assertDatabaseHas('task_distribution_channels', [
            'task_id' => $task->id,
            'distribution_channel_id' => $channel->id,
        ]);
    }

    public function test_tasks_write_scope_cannot_preserve_or_execute_an_auto_publishing_task(): void
    {
        $admin = $this->createActiveAdmin('task_scope_boundary_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $updatedTask = Task::query()->create([
            'name' => 'Auto publishing task to update',
            'status' => 'paused',
            'need_review' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->patchJson("/api/v1/tasks/{$updatedTask->id}", ['name' => 'Review-bound task'])
            ->assertOk()
            ->assertJsonPath('data.need_review', 1);

        $autoTask = Task::query()->create([
            'name' => 'Auto publishing task to enqueue',
            'status' => 'active',
            'schedule_enabled' => 1,
            'need_review' => false,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$autoTask->id}/enqueue")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', 'articles:publish');
    }

    public function test_super_admin_api_can_manage_a_hosted_site_task(): void
    {
        Queue::fake();
        $admin = $this->createActiveAdmin('hosted_super_admin', 'p');
        $admin->forceFill(['role' => 'super_admin'])->save();
        $bearer = $this->createBearerToken($admin, ['tasks:read', 'tasks:write']);
        $channel = DistributionChannel::query()->create([
            'name' => 'Super admin hosted task channel',
            'domain' => 'super-admin-hosted-task.test',
            'endpoint_url' => 'https://super-admin-hosted-task.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $library = TitleLibrary::query()->create([
            'name' => 'Super admin hosted titles',
            'description' => '',
            'title_count' => 1,
        ]);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'Hosted task title',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $task = Task::query()->create([
            'name' => 'Super admin hosted API task',
            'status' => 'paused',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'title_library_id' => $library->id,
            'article_limit' => 1,
            'created_count' => 0,
            'is_loop' => false,
        ]);
        $task->distributionChannels()->attach($channel->id);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->patchJson("/api/v1/tasks/{$task->id}", ['name' => 'Updated hosted API task'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated hosted API task');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/start", ['enqueue_now' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/enqueue")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/stop")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertNotNull(Task::onlyTrashed()->find($task->id));
    }

    public function test_article_task_reference_is_locked_inside_the_create_transaction(): void
    {
        $admin = $this->createActiveAdmin('article_task_lock_admin', 'p');
        $task = Task::query()->create(['name' => 'Article reference lock task', 'status' => 'paused']);
        $category = Category::query()->create(['name' => 'Article lock category', 'slug' => 'article-lock-category']);
        $author = Author::query()->create(['name' => 'Article lock author']);
        $events = [];

        DB::listen(function (QueryExecuted $query) use (&$events): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'from "tasks"')) {
                $events[] = ['type' => 'task_select', 'transaction_level' => DB::transactionLevel()];
            }
            if (str_starts_with(ltrim($sql), 'insert') && str_contains($sql, 'into "articles"')) {
                $events[] = ['type' => 'article_insert', 'transaction_level' => DB::transactionLevel()];
            }
        });

        app(ArticleGeoFlowService::class)->createArticle([
            'title' => 'Article task lock regression',
            'content' => 'A safe article body [K1]. Vitamin K2 stays.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'is_ai_generated' => 1,
        ], (int) $admin->id);

        $insertIndex = collect($events)->search(fn (array $event): bool => $event['type'] === 'article_insert');
        $this->assertIsInt($insertIndex);
        $this->assertTrue(collect($events)->take($insertIndex)->contains(
            fn (array $event): bool => $event['type'] === 'task_select' && $event['transaction_level'] > 0,
        ), json_encode($events));
        $this->assertDatabaseHas('articles', [
            'title' => 'Article task lock regression',
            'content' => 'A safe article body. Vitamin K2 stays.',
        ]);
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

        $response->assertJsonPath('data.ai_quality_timeout_sampling_enabled', false);

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'image_library_id' => null,
            'author_id' => null,
            'knowledge_base_id' => null,
            'fixed_category_id' => null,
        ]);
    }

    public function test_task_api_rejects_timeout_sampling_when_ai_quality_is_disabled(): void
    {
        $admin = $this->createActiveAdmin('timeout_sampling_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write', 'tasks:read']);
        $model = AiModel::query()->create([
            'name' => 'Timeout Sampling API Model',
            'model_id' => 'timeout-sampling-api-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Timeout Sampling API Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Timeout Sampling API Titles',
            'description' => '',
            'title_count' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/tasks', [
                'name' => 'Timeout sampling API task',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
                'status' => 'paused',
                'category_mode' => 'smart',
                'draft_limit' => 1,
                'article_limit' => 1,
                'ai_quality_enabled' => false,
                'ai_quality_timeout_sampling_enabled' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.ai_quality_enabled', false)
            ->assertJsonPath('data.ai_quality_timeout_sampling_enabled', false);

        $this->assertFalse(Task::query()->findOrFail((int) $response->json('data.id'))
            ->ai_quality_timeout_sampling_enabled);
    }

    public function test_task_create_prefers_knowledge_base_ids_over_legacy_knowledge_base_id(): void
    {
        $admin = $this->createActiveAdmin('u10', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $model = AiModel::query()->create([
            'name' => 'Task Create Model With Knowledge',
            'model_id' => 'task-create-model-with-knowledge',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Task Create Prompt With Knowledge',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Task Create Titles With Knowledge',
            'description' => '',
            'title_count' => 0,
        ]);
        $legacyKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Legacy Knowledge',
            'description' => '',
            'content' => 'Legacy content',
            'file_type' => 'markdown',
            'character_count' => 14,
            'word_count' => 14,
        ]);
        $firstKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Primary Knowledge',
            'description' => '',
            'content' => 'Primary content',
            'file_type' => 'markdown',
            'character_count' => 15,
            'word_count' => 15,
        ]);
        $secondKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Secondary Knowledge',
            'description' => '',
            'content' => 'Secondary content',
            'file_type' => 'markdown',
            'character_count' => 17,
            'word_count' => 17,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/tasks', [
                'name' => 'API create task with multiple knowledge bases',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
                'status' => 'paused',
                'category_mode' => 'smart',
                'draft_limit' => 1,
                'article_limit' => 1,
                'knowledge_base_id' => (int) $legacyKnowledgeBase->id,
                'knowledge_base_ids' => [
                    (int) $firstKnowledgeBase->id,
                    (int) $secondKnowledgeBase->id,
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.knowledge_base_id', (int) $firstKnowledgeBase->id)
            ->assertJsonPath('data.knowledge_base_ids.0', (int) $firstKnowledgeBase->id)
            ->assertJsonPath('data.knowledge_base_ids.1', (int) $secondKnowledgeBase->id)
            ->assertJsonCount(2, 'data.knowledge_base_ids');

        $taskId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'knowledge_base_id' => (int) $firstKnowledgeBase->id,
        ]);
        $this->assertDatabaseHas('task_knowledge_bases', [
            'task_id' => $taskId,
            'knowledge_base_id' => (int) $firstKnowledgeBase->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('task_knowledge_bases', [
            'task_id' => $taskId,
            'knowledge_base_id' => (int) $secondKnowledgeBase->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseMissing('task_knowledge_bases', [
            'task_id' => $taskId,
            'knowledge_base_id' => (int) $legacyKnowledgeBase->id,
        ]);
    }

    public function test_task_lifecycle_failure_after_inner_commit_preserves_outer_transaction_ownership(): void
    {
        $task = Task::query()->create([
            'name' => 'Outer transaction owner',
            'status' => 'paused',
        ]);
        $monitoring = Mockery::mock(TaskMonitoringQueryService::class);
        $monitoring->shouldReceive('getTaskMonitoringDetail')
            ->once()
            ->andThrow(new \RuntimeException('post-inner-read-failure'));
        $realtime = Mockery::mock(TaskRealtimeBroadcastService::class);
        $realtime->shouldReceive('broadcastOverview')->never();
        $service = new TaskLifecycleService(
            app(JobQueueService::class),
            $monitoring,
            $realtime,
            app(TaskTitleReadinessService::class),
            app(ArticleAiQualityInvalidationService::class),
        );

        $baselineTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $service->updateTask((int) $task->id, ['name' => 'Updated inside outer transaction']);
            $this->fail('The monitoring failure should escape the lifecycle service.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-inner-read-failure', $exception->getMessage());
        }

        $this->assertSame($baselineTransactionLevel + 1, DB::transactionLevel());
        DB::rollBack();
        $this->assertSame('Outer transaction owner', $task->fresh()->name);
    }

    public function test_material_api_cannot_delete_knowledge_base_referenced_by_task_pivot(): void
    {
        $admin = $this->createActiveAdmin('u11', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:write']);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'API Referenced Knowledge',
            'description' => '',
            'content' => 'Referenced content',
            'file_type' => 'markdown',
            'character_count' => 18,
            'word_count' => 18,
        ]);
        $task = Task::query()->create([
            'name' => 'API task uses knowledge',
            'status' => 'paused',
            'knowledge_base_id' => null,
        ]);
        $task->knowledgeBases()->attach((int) $knowledgeBase->id, ['sort_order' => 0]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson('/api/v1/materials/knowledge-bases/'.(int) $knowledgeBase->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'material_in_use')
            ->assertJsonPath('error.details.task_count', 1);

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
        ]);
    }

    public function test_material_api_cannot_delete_category_referenced_by_trashed_task(): void
    {
        $admin = $this->createActiveAdmin('category_task_guard_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:write']);
        $category = Category::query()->create([
            'name' => 'Protected task category',
            'slug' => 'protected-task-category',
        ]);
        $task = Task::query()->create([
            'name' => 'Task retaining fixed category',
            'status' => 'paused',
            'category_mode' => 'fixed',
            'fixed_category_id' => $category->id,
        ]);
        $task->delete();

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson('/api/v1/materials/categories/'.(int) $category->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'material_in_use')
            ->assertJsonPath('error.details.task_count', 1);

        $this->assertNotNull(Category::query()->find($category->id));
    }

    public function test_material_api_counts_trashed_articles_and_keeps_their_author_protected(): void
    {
        $admin = $this->createActiveAdmin('trashed_article_author_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);
        $author = Author::query()->create(['name' => 'API trashed article author']);
        $category = Category::query()->create([
            'name' => 'API trashed article category',
            'slug' => 'api-trashed-article-category',
        ]);
        $article = Article::query()->create([
            'title' => 'API trashed author article',
            'slug' => 'api-trashed-author-article',
            'content' => 'Article retained in the trash.',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);
        $article->delete();

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials/authors')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $author->id)
            ->assertJsonPath('data.items.0.article_count', 0)
            ->assertJsonPath('data.items.0.trashed_count', 1);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson('/api/v1/materials/authors/'.(int) $author->id)
            ->assertConflict()
            ->assertJsonPath('error.code', 'material_in_use')
            ->assertJsonPath('error.details.trashed_count', 1);

        $this->assertModelExists($author);
    }
}
