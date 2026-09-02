<?php

namespace Tests\Feature;

use App\Data\Api\TaskRunData;
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
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\AiQualityAuditService;
use App\Services\GeoFlow\AiQualityRetrievalReadinessService;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
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
            ->patchJson("/api/v1/tasks/{$task->id}", ['name' => 'Unauthorized update', 'config_version' => 1])
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
            ->patchJson("/api/v1/tasks/{$updatedTask->id}", [
                'name' => 'Review-bound task',
                'config_version' => 1,
            ])
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

    public function test_restricted_task_token_must_supply_the_current_quality_config_version(): void
    {
        $admin = $this->createActiveAdmin('task_config_version_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $task = Task::query()->create([
            'name' => 'CAS protected task',
            'status' => 'paused',
            'need_review' => true,
            'ai_quality_config_version' => 4,
            'ai_quality_policy_version' => 4,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->patchJson("/api/v1/tasks/{$task->id}", [
                'ai_quality_timeout_sampling_enabled' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'task_ai_quality_config_version_required')
            ->assertJsonPath('error.details.required_field', 'config_version');

        $this->assertFalse((bool) $task->fresh()->ai_quality_timeout_sampling_enabled);
        $this->assertSame(4, (int) $task->fresh()->ai_quality_config_version);
    }

    public function test_job_list_and_detail_return_only_sanitized_public_fields(): void
    {
        $admin = $this->createActiveAdmin('safe_job_dto_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:read', 'jobs:read']);
        $task = Task::query()->create([
            'name' => 'Safe job DTO task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'error_message' => 'provider https://secret.example.test?token=error-token api_key=error-key',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 1,
                'max_attempts' => 3,
                'worker_id' => 'safe-worker',
                'payload' => [
                    'source' => 'api_enqueue',
                    'api_key' => 'historical-payload-key',
                    'nested' => ['password' => 'historical-nested-password'],
                ],
                'model_attempts' => [[
                    'status' => 'failed',
                    'reason' => 'Authorization: Bearer historical-bearer',
                    'base_url' => 'https://historical.example.test?token=historical-url-token',
                    'ai_config_access_version' => 456789,
                    'execution_lease_token' => 'nested-lease-secret',
                ]],
                'ai_quality' => [
                    'required' => true,
                    'check_id' => 778899,
                    'status' => 'queued',
                    'nested' => [[
                        'MODEL_ID' => 112233,
                        'Model_Name' => 'Nested Historical Private Model',
                        'Api_Url' => 'https://nested-provider.example.test/v1',
                        'ENDPOINT' => 'https://nested-endpoint.example.test',
                        'Prompt' => 'nested private prompt',
                        'CONTENT' => 'nested private provider content',
                        'Provider_Response' => 'nested provider response secret',
                        'safe_status' => 'still-safe',
                        'diagnostic' => 'https://camouflaged.example.test sk-camouflaged-secret Camouflaged Private Model',
                    ]],
                ],
                'title_readiness' => [
                    'status' => 'blocked',
                    'can_save' => false,
                    'can_activate' => false,
                    'requires_acknowledgement' => true,
                    'shortage' => 2,
                    'suggested_article_limit' => 3,
                    'conflict_count' => 1,
                    'library' => [
                        'id' => 991122,
                        'name' => 'Peer Model Hidden In Library Name',
                        'total' => 5,
                        'used' => 2,
                        'available' => 3,
                        'diagnostic' => 'Peer Model Hidden In Library Diagnostic',
                    ],
                    'task' => [
                        'id' => (int) $task->id,
                        'status' => 'active',
                        'article_limit' => 5,
                        'created_count' => 2,
                        'remaining' => 3,
                        'is_loop' => false,
                        'identifier' => 991122,
                    ],
                    'issues' => [[
                        'code' => 'title_library_shortage',
                        'severity' => 'blocking',
                        'label' => 'Peer Model Hidden In Issue Label',
                    ], [
                        'code' => 'untrusted_peer_model_name',
                        'severity' => 'blocking',
                    ]],
                    'diagnostic' => 'Peer Model Hidden In Readiness Diagnostic',
                    'identifier' => 991122,
                    'value' => 'Peer Model Hidden In Value',
                    'label' => 'Peer Model Hidden In Label',
                    'MODEL_ATTEMPTS' => [['model_name' => 'Attempt Private Model']],
                ],
                'used_model_id' => 246810,
                'used_model_name' => 'Historical Private Model Name',
                'note' => 'historical-note-secret',
                'model_access_admin_id' => 999999,
            ],
        ]);
        $run->forceFill([
            'error_code' => 'ai_config_access_revoked',
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 987654,
            'requested_ai_model_id' => null,
            'resolved_ai_model_id' => null,
            'resolved_model_source' => 'personal',
            'resolver_policy_version' => 876543,
            'execution_lease_token' => '09070375-aa55-4f2f-b23b-5fc463ea42bc',
        ])->save();

        $listResponse = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson("/api/v1/tasks/{$task->id}/jobs")
            ->assertOk();
        $listItem = $listResponse->json('data.items.0');
        $this->assertIsArray($listItem);
        $this->assertSame((int) $run->id, (int) data_get($listItem, 'id'));
        $this->assertSame('running', data_get($listItem, 'status'));
        $this->assertSame('api_enqueue', data_get($listItem, 'payload.source'));
        $this->assertSame('ai_config_access_revoked', data_get($listItem, 'error_code'));
        $this->assertSame('任务执行未完成', data_get($listItem, 'error_message'));
        foreach (['execution_lease_token', 'model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'requested_ai_model_id', 'resolved_ai_model_id', 'resolved_model_source', 'resolver_policy_version', 'meta'] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $listItem);
        }
        foreach (['model_attempts', 'used_model_id', 'used_model_name'] as $modelReferenceKey) {
            $this->assertArrayNotHasKey($modelReferenceKey, data_get($listItem, 'task_run_summary.meta', []));
        }

        $detailResponse = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson("/api/v1/jobs/{$run->id}")
            ->assertOk();
        $detail = $detailResponse->json('data');
        $this->assertIsArray($detail);
        $this->assertSame((int) $run->id, (int) data_get($detail, 'id'));
        $this->assertSame('generate_article', data_get($detail, 'job_type'));
        $this->assertSame('api_enqueue', data_get($detail, 'payload.source'));
        $this->assertSame('ai_config_access_revoked', data_get($detail, 'error_code'));
        $this->assertSame('任务执行未完成', data_get($detail, 'error_message'));
        $this->assertSame([
            'required' => true,
            'check_id' => 778899,
            'status' => 'queued',
        ], data_get($detail, 'task_run_summary.meta.ai_quality'));
        $this->assertSame([
            'status' => 'blocked',
            'can_save' => false,
            'can_activate' => false,
            'requires_acknowledgement' => true,
            'library' => [
                'total' => 5,
                'used' => 2,
                'available' => 3,
            ],
            'task' => [
                'id' => (int) $task->id,
                'status' => 'active',
                'article_limit' => 5,
                'created_count' => 2,
                'remaining' => 3,
                'is_loop' => false,
            ],
            'shortage' => 2,
            'suggested_article_limit' => 3,
            'conflict_count' => 1,
            'issues' => [[
                'code' => 'title_library_shortage',
                'severity' => 'blocking',
            ]],
        ], data_get($detail, 'task_run_summary.meta.title_readiness'));
        foreach (['model_attempts', 'used_model_id', 'used_model_name'] as $modelReferenceKey) {
            $this->assertArrayNotHasKey($modelReferenceKey, data_get($detail, 'task_run_summary.meta', []));
        }

        $serialized = json_encode([$listItem, $detail], JSON_THROW_ON_ERROR);
        foreach ([
            '09070375-aa55-4f2f-b23b-5fc463ea42bc',
            '987654',
            '876543',
            'secret.example.test',
            'error-token',
            'error-key',
            'historical-payload-key',
            'historical-nested-password',
            'historical-bearer',
            'historical.example.test',
            'historical-url-token',
            'historical-note-secret',
            '999999',
            '456789',
            'nested-lease-secret',
            '246810',
            'Historical Private Model Name',
            'Nested Historical Private Model',
            'nested-provider.example.test',
            'nested-endpoint.example.test',
            'nested private prompt',
            'nested private provider content',
            'nested provider response secret',
            'readiness.example.test',
            'readiness-key',
            'Private Readiness Model',
            'Attempt Private Model',
            '112233',
            'camouflaged.example.test',
            'sk-camouflaged-secret',
            'Camouflaged Private Model',
            'Peer Model Hidden In Library Name',
            'Peer Model Hidden In Library Diagnostic',
            'Peer Model Hidden In Issue Label',
            'Peer Model Hidden In Readiness Diagnostic',
            'Peer Model Hidden In Value',
            'Peer Model Hidden In Label',
            'untrusted_peer_model_name',
            '991122',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        $run->forceFill([
            'error_code' => 'historical_private_model_name',
            'error_message' => 'historical_private_model_name',
        ])->save();
        $unsafeHistoricalCode = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/jobs/'.(int) $run->id)
            ->assertOk()
            ->assertJsonPath('data.error_code', 'task_execution_failed')
            ->assertJsonPath('data.error_message', '任务执行未完成');
        $this->assertStringNotContainsString('historical_private_model_name', $unsafeHistoricalCode->getContent());
    }

    public function test_job_detail_rejects_an_active_token_owner_with_an_invalid_execution_role(): void
    {
        $admin = $this->createActiveAdmin('invalid_job_viewer_role', 'p');
        $bearer = $this->createBearerToken($admin, ['jobs:read', 'tasks:read']);
        $task = Task::query()->create(['name' => 'Invalid viewer task', 'status' => 'paused']);
        $run = TaskRun::query()->create(['task_id' => $task->id, 'status' => 'failed']);
        $admin->forceFill(['role' => 'auditor'])->save();

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/jobs/'.(int) $run->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ai_execution_admin_inactive');

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/tasks/'.(int) $task->id.'/jobs')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ai_execution_admin_inactive');

        $admin->forceFill(['role' => 'admin', 'status' => 'inactive'])->save();
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/jobs/'.(int) $run->id)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');

        $admin->forceFill(['status' => 'active'])->save();
        $admin->delete();
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/jobs/'.(int) $run->id)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');
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
            ->patchJson("/api/v1/tasks/{$task->id}", ['name' => 'Updated hosted API task', 'config_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated hosted API task');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/start", ['enqueue_now' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/tasks/{$task->id}/enqueue", [
                'job_type' => 'generate_article',
                'api_key' => 'api-request-secret',
                'base_url' => 'https://provider.example.test?token=url-secret',
                'note' => 'Bearer note-secret',
                'nested' => ['password' => 'nested-secret'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $queuedMeta = TaskRun::query()->where('task_id', $task->id)->firstOrFail()->meta;
        $this->assertSame(['source' => 'api_enqueue'], data_get($queuedMeta, 'payload'));
        $serializedMeta = json_encode($queuedMeta, JSON_THROW_ON_ERROR);
        foreach (['api-request-secret', 'provider.example.test', 'url-secret', 'note-secret', 'nested-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serializedMeta);
        }
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
        $this->assignModelOwner($model, $admin);
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
        $this->assignModelOwner($model, $admin);
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
        $this->assignModelOwner($model, $admin);
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
            ->with((int) $task->id, null)
            ->once()
            ->andThrow(new \RuntimeException('post-inner-read-failure'));
        $realtime = Mockery::mock(TaskRealtimeBroadcastService::class);
        $realtime->shouldReceive('broadcastOverview')->never();
        $service = new TaskLifecycleService(
            app(JobQueueService::class),
            app(AiExecutionContextFactory::class),
            $monitoring,
            $realtime,
            app(TaskTitleReadinessService::class),
            app(ArticleAiQualityInvalidationService::class),
            app(ArticleAiQualityPolicyResolver::class),
            app(AiQualityRetrievalReadinessService::class),
            app(AiQualityAuditService::class),
            app(TaskRunData::class),
            app(AdminAiModelAccessResolver::class),
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

    private function assignModelOwner(AiModel $model, Admin $admin): void
    {
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
    }
}
