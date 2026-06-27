<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 素材管理模块最小可用测试：
 * - 路由鉴权
 * - 主要列表/创建页可访问
 * - 知识库创建链路可用
 */
class AdminMaterialsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createReadyUrlImportAiModel(string $apiUrl = 'https://ai.test/v1'): AiModel
    {
        return AiModel::query()->create([
            'name' => 'URL Import AI Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => $apiUrl,
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }

    public function test_guest_is_redirected_from_material_pages(): void
    {
        $routes = [
            'admin.materials.index',
            'admin.authors.index',
            'admin.keyword-libraries.index',
            'admin.title-libraries.index',
            'admin.image-libraries.index',
            'admin.knowledge-bases.index',
            'admin.url-import',
            'admin.url-import.history',
        ];

        foreach ($routes as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('admin.login'));
        }

        $this->get(route('admin.keyword-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.title-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.image-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => 1]))->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_open_material_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_admin',
            'password' => 'secret-123',
            'email' => 'materials-admin@example.com',
            'display_name' => 'Materials Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.page_title'))
            ->assertSee(__('admin.materials.knowledge_hub_label'))
            ->assertSee(__('admin.materials.knowledge_hub_vector_progress'))
            ->assertSee(__('admin.materials.evidence_layer_title'))
            ->assertSeeInOrder([
                __('admin.materials.knowledge_hub_create'),
                __('admin.materials.manage_knowledge_bases'),
                __('admin.materials.knowledge_hub_vector_config'),
            ])
            ->assertSee(__('admin.materials.foundation_title'))
            ->assertSee(__('admin.materials.author_manage_title'))
            ->assertSee(__('admin.materials.url_import'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.index'))
            ->assertOk()
            ->assertSee(__('admin.authors.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.keyword_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.title_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.image_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.create'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.page_title'))
            ->assertSeeInOrder([
                __('admin.knowledge_bases.source_files_title'),
                __('admin.knowledge_bases.source_text_title'),
            ])
            ->assertSee(__('admin.knowledge_bases.evidence_metadata_title'))
            ->assertSee('data-knowledge-name-input', false)
            ->assertSee('data-knowledge-description-input', false)
            ->assertSee('data-import-client-error', false)
            ->assertSee(__('admin.knowledge_bases.import_error_title'))
            ->assertSee('name="knowledge_files[]"', false)
            ->assertSee('multiple', false)
            ->assertSee('data-knowledge-upload-dropzone', false)
            ->assertSeeInOrder([
                __('admin.button.cancel'),
                __('admin.knowledge_bases.import_submit_only'),
                __('admin.knowledge_bases.import_submit'),
            ])
            ->assertSee('name="import_action" value="save"', false)
            ->assertSee('name="import_action" value="save_and_chunk"', false)
            ->assertSee('50MB')
            ->assertSee('10');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee(__('admin.url_import.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.history'))
            ->assertOk()
            ->assertSee(__('admin.url_import_history.page_title'));
    }

    public function test_materials_page_counts_high_risk_unreviewed_knowledge_as_pending(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_evidence_admin',
            'password' => 'secret-123',
            'email' => 'materials-evidence-admin@example.com',
            'display_name' => 'Materials Evidence Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        KnowledgeBase::query()->create([
            'name' => '待审核高风险知识',
            'content' => '包含待确认风险表述。',
            'file_type' => 'markdown',
            'risk_level' => 'high',
            'review_status' => 'unreviewed',
        ]);
        KnowledgeBase::query()->create([
            'name' => '待审核高风险知识 2',
            'content' => '另一条待确认风险表述。',
            'file_type' => 'markdown',
            'risk_level' => 'high',
            'review_status' => 'unreviewed',
        ]);
        KnowledgeBase::query()->create([
            'name' => '已审核高风险知识',
            'content' => '已经人工确认。',
            'file_type' => 'markdown',
            'risk_level' => 'high',
            'review_status' => 'reviewed',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.evidence_risk_title'))
            ->assertSee(__('admin.materials.evidence_risk_desc'))
            ->assertSee('>2<', false);
    }

    public function test_admin_can_create_knowledge_base_from_form(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_create_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-create-admin@example.com',
            'display_name' => 'Knowledge Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '测试知识库',
                'description' => '测试描述',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
                'source_name' => 'GEOFlow 官方文档',
                'source_url' => 'https://example.com/geoflow',
                'source_type' => 'document',
                'business_line' => 'GEO 内容工程',
                'effective_date' => '2026-05-01',
                'risk_level' => 'low',
                'review_status' => 'reviewed',
            ]);

        $response->assertRedirect(route('admin.knowledge-bases.index'));
        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '测试知识库',
            'file_type' => 'markdown',
            'source_name' => 'GEOFlow 官方文档',
            'source_url' => 'https://example.com/geoflow',
            'source_type' => 'document',
            'business_line' => 'GEO 内容工程',
            'effective_date' => '2026-05-01 00:00:00',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);
        $this->assertGreaterThan(0, KnowledgeBase::query()->count());
    }

    public function test_admin_can_create_knowledge_base_from_multiple_uploaded_files(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_multi_upload_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-multi-upload-admin@example.com',
            'display_name' => 'Knowledge Multi Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '批量合并知识库',
                'description' => '多文件合并测试',
                'file_type' => 'markdown',
                'content' => "手动输入的 GEO 背景。\n\n第二段。",
                'knowledge_files' => [
                    UploadedFile::fake()->createWithContent('alpha.md', "# Alpha\nMarkdown 内容"),
                    UploadedFile::fake()->createWithContent('beta.txt', 'Beta 文本内容'),
                ],
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '批量合并知识库')->firstOrFail();
        $this->assertSame('markdown', (string) $knowledgeBase->file_type);
        $this->assertStringContainsString('# 手动输入内容', (string) $knowledgeBase->content);
        $this->assertStringContainsString('# 文件：alpha.md', (string) $knowledgeBase->content);
        $this->assertStringContainsString('# 文件：beta.txt', (string) $knowledgeBase->content);

        $storedPaths = json_decode((string) $knowledgeBase->file_path, true);
        $this->assertIsArray($storedPaths);
        $this->assertCount(2, $storedPaths);
        foreach ($storedPaths as $storedPath) {
            Storage::disk('local')->assertExists((string) $storedPath);
        }
    }

    public function test_admin_can_create_text_only_knowledge_base_without_manual_name(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_text_auto_name_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-text-auto-name-admin@example.com',
            'display_name' => 'Knowledge Text Auto Name Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '',
                'description' => '',
                'file_type' => 'markdown',
                'content' => "# GEO 白皮书\n\n这是一段直接粘贴的知识库内容。",
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'GEO 白皮书',
            'file_type' => 'markdown',
        ]);
    }

    public function test_admin_can_submit_knowledge_base_without_generating_chunks(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_submit_only_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-submit-only-admin@example.com',
            'display_name' => 'Knowledge Submit Only Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->mock(KnowledgeChunkSyncService::class, function ($mock): void {
            $mock->shouldNotReceive('sync');
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '仅提交知识库',
                'description' => '',
                'file_type' => 'markdown',
                'content' => "# 仅保存\n\n稍后再生成切片。",
                'import_action' => 'save',
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHas('message', __('admin.knowledge_bases.message.create_saved'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '仅提交知识库')->firstOrFail();
        $this->assertSame(0, $knowledgeBase->chunks()->count());
    }

    public function test_create_keeps_knowledge_base_when_chunk_sync_fails(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_chunk_failure_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-chunk-failure-admin@example.com',
            'display_name' => 'Knowledge Chunk Failure Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->mock(KnowledgeChunkSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->andThrow(new \RuntimeException('embedding timeout'));
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '已保存但切片失败',
                'description' => '',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHasErrors('chunk_sync');

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '已保存但切片失败',
            'content' => "第一段内容。\n\n第二段内容。",
        ]);
        $this->assertSame(0, KnowledgeBase::query()->where('name', '已保存但切片失败')->firstOrFail()->chunks()->count());
    }

    public function test_detail_update_keeps_changes_when_chunk_sync_fails(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_detail_chunk_failure_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-detail-chunk-failure-admin@example.com',
            'display_name' => 'Knowledge Detail Chunk Failure Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '原始知识库',
            'description' => '',
            'content' => '原始内容',
            'character_count' => 4,
            'file_type' => 'markdown',
            'word_count' => 4,
        ]);

        $this->mock(KnowledgeChunkSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->andThrow(new \RuntimeException('semantic planner timeout'));
        });

        $this->actingAs($admin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]), [
                'name' => '更新后的知识库',
                'description' => '更新说明',
                'file_type' => 'markdown',
                'content' => '更新后的正文内容',
            ])
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertSessionHasErrors('chunk_sync');

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
            'name' => '更新后的知识库',
            'description' => '更新说明',
            'content' => '更新后的正文内容',
        ]);
    }

    public function test_admin_cannot_upload_more_than_ten_knowledge_files(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_file_limit_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-file-limit-admin@example.com',
            'display_name' => 'Knowledge File Limit Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $files = [];
        for ($index = 1; $index <= 11; $index++) {
            $files[] = UploadedFile::fake()->createWithContent("source-{$index}.md", "第 {$index} 份资料");
        }

        $this->actingAs($admin, 'admin')
            ->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '超量知识库',
                'description' => '',
                'file_type' => 'markdown',
                'content' => '',
                'knowledge_files' => $files,
            ])
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('knowledge_files');

        $this->assertDatabaseMissing('knowledge_bases', [
            'name' => '超量知识库',
        ]);
    }

    public function test_admin_cannot_upload_knowledge_file_larger_than_fifty_mb(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_file_size_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-file-size-admin@example.com',
            'display_name' => 'Knowledge File Size Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '超大知识库',
                'description' => '',
                'file_type' => 'markdown',
                'content' => '',
                'knowledge_files' => [
                    UploadedFile::fake()->create('large.md', 50 * 1024 + 1, 'text/markdown'),
                ],
            ])
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('knowledge_files.0');

        $this->assertDatabaseMissing('knowledge_bases', [
            'name' => '超大知识库',
        ]);
    }

    public function test_knowledge_base_index_uses_unified_import_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_unified_import_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-unified-import-admin@example.com',
            'display_name' => 'Knowledge Unified Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index'))
            ->assertOk()
            ->assertSee(route('admin.knowledge-bases.create', ['mode' => 'upload']), false)
            ->assertDontSee('upload-modal', false)
            ->assertDontSee('showUploadModal', false);
    }

    public function test_deleting_multi_file_knowledge_base_cleans_all_stored_files(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_delete_files_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-delete-files-admin@example.com',
            'display_name' => 'Knowledge Delete Files Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Storage::disk('local')->put('knowledge-bases/2026/alpha.md', '# Alpha');
        Storage::disk('local')->put('knowledge-bases/2026/beta.md', '# Beta');

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '待删除多文件知识库',
            'description' => '',
            'content' => "# Alpha\n\n# Beta",
            'character_count' => 16,
            'file_type' => 'markdown',
            'word_count' => 16,
            'file_path' => json_encode([
                'knowledge-bases/2026/alpha.md',
                'knowledge-bases/2026/beta.md',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.delete', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index'));

        Storage::disk('local')->assertMissing('knowledge-bases/2026/alpha.md');
        Storage::disk('local')->assertMissing('knowledge-bases/2026/beta.md');
        $this->assertDatabaseMissing('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
        ]);
    }

    public function test_admin_cannot_delete_knowledge_base_referenced_by_task_pivot(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_delete_pivot_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-delete-pivot-admin@example.com',
            'display_name' => 'Knowledge Delete Pivot Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '被任务引用知识库',
            'description' => '',
            'content' => '被任务引用的知识库不能删除。',
            'character_count' => 15,
            'file_type' => 'markdown',
            'word_count' => 15,
        ]);
        $task = Task::query()->create([
            'name' => '引用知识库任务',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
            'knowledge_base_id' => null,
        ]);
        $task->knowledgeBases()->attach((int) $knowledgeBase->id, ['sort_order' => 0]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.knowledge-bases.index'))
            ->post(route('admin.knowledge-bases.delete', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
        ]);
        $this->assertDatabaseHas('task_knowledge_bases', [
            'task_id' => (int) $task->id,
            'knowledge_base_id' => (int) $knowledgeBase->id,
        ]);
    }

    public function test_admin_can_refresh_knowledge_chunks_with_real_embedding_model(): void
    {
        Http::fake([
            'https://ai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $admin = Admin::query()->create([
            'username' => 'knowledge_refresh_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-refresh-admin@example.com',
            'display_name' => 'Knowledge Refresh Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $embeddingModel = AiModel::query()->create([
            'name' => 'Test Embedding',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://ai.test',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '待向量化知识库',
            'description' => 'desc',
            'content' => 'GEOFlow 支持知识库切片和向量化检索。',
            'character_count' => 22,
            'file_type' => 'markdown',
            'word_count' => 22,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.refresh_chunks'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHas('message');

        $chunk = $knowledgeBase->chunks()->firstOrFail();
        $this->assertSame((int) $embeddingModel->id, (int) $chunk->embedding_model_id);
        $this->assertSame(3, (int) $chunk->embedding_dimensions);
        $this->assertSame([0.1, 0.2, 0.3], json_decode((string) $chunk->embedding_json, true));
    }

    public function test_knowledge_base_list_uses_friendly_refresh_chunks_progress_ui(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_refresh_ui_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-refresh-ui-admin@example.com',
            'display_name' => 'Knowledge Refresh UI Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        AiModel::query()->create([
            'name' => 'Test Embedding',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://ai.test',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        KnowledgeBase::query()->create([
            'name' => '待更新切片知识库',
            'description' => 'desc',
            'content' => 'GEOFlow 支持知识库切片和向量化检索。',
            'character_count' => 22,
            'file_type' => 'markdown',
            'word_count' => 22,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index'))
            ->assertOk()
            ->assertSee('data-knowledge-refresh-modal', false)
            ->assertSee('data-refresh-chunks-form', false)
            ->assertSee('data-refresh-progress', false)
            ->assertSee(__('admin.knowledge_bases.refresh_confirm_title'))
            ->assertSee(__('admin.knowledge_bases.refresh_progress_initial'))
            ->assertDontSee(__('admin.knowledge_bases.confirm_refresh_chunks', ['name' => '待更新切片知识库']));
    }

    public function test_refresh_knowledge_chunks_requires_embedding_model(): void
    {
        Http::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_no_embedding_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-no-embedding-admin@example.com',
            'display_name' => 'Knowledge No Embedding Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '无向量模型知识库',
            'description' => 'desc',
            'content' => '没有 embedding 模型时不能把 fallback 当作真实向量。',
            'character_count' => 28,
            'file_type' => 'markdown',
            'word_count' => 28,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHasErrors();

        $this->assertSame(0, $knowledgeBase->chunks()->count());
        Http::assertNothingSent();
    }

    public function test_admin_can_create_url_import_job_without_url_scheme(): void
    {
        Http::fake([
            'https://example.test/report' => Http::response(
                '<!doctype html><html><head><title>示例项目</title><meta name="description" content="示例项目页面摘要"></head><body><main><h1>示例项目</h1><p>这是一个用于采集测试的 GEO 页面。</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_admin',
            'password' => 'secret-123',
            'email' => 'url-import-admin@example.com',
            'display_name' => 'Url Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'project_name' => '示例项目',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('url_import_jobs', [
            'url' => 'example.test/report',
            'normalized_url' => 'https://example.test/report',
            'source_domain' => 'example.test',
            'status' => 'queued',
            'created_by' => 'url_import_admin',
        ]);

        $job = UrlImportJob::query()->firstOrFail();
        config(['app.url' => 'https://configured.example']);
        $runPath = route('admin.url-import.run', ['jobId' => (int) $job->id], false);
        $statusPath = route('admin.url-import.status', ['jobId' => (int) $job->id], false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.show', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertSee('data-run-url="'.$runPath.'"', false)
            ->assertSee('data-status-url="'.$statusPath.'"', false)
            ->assertSee('data-status="queued"', false)
            ->assertSee('data-has-result="0"', false)
            ->assertDontSee('https://configured.example'.$runPath, false)
            ->assertDontSee('https://configured.example'.$statusPath, false)
            ->assertDontSee('sessionStorage', false)
            ->assertDontSee('setTimeout(() => window.location.reload(), 1000)', false);

        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'status' => 'queued',
            'current_step' => 'queued',
        ]);
    }

    public function test_url_import_requires_ready_ai_model_before_creating_job(): void
    {
        $admin = Admin::query()->create([
            'username' => 'url_import_no_model_admin',
            'password' => 'secret-123',
            'email' => 'url-import-no-model@example.com',
            'display_name' => 'Url Import No Model Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHasErrors('ai_model');

        $this->assertDatabaseCount('url_import_jobs', 0);
    }

    public function test_admin_can_run_and_commit_url_import_job(): void
    {
        Http::fake([
            'https://example.test/report' => Http::response(
                '<!doctype html><html><head><title>GEO 内容报告</title><meta name="description" content="这是一份关于 GEO 内容系统的页面摘要"><meta property="og:image" content="https://example.test/cover.jpg"></head><body><article><h1>GEO 内容报告</h1><p>GEO 内容系统需要知识库、关键词库和标题库协同工作。</p><img src="/body.png" alt="正文配图"></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'clean_title' => 'GEO 内容报告',
                                'clean_summary' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'clean_text' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'core_business' => [
                                    'industry' => 'GEO 内容系统',
                                    'products_services' => ['内容资产管理'],
                                    'target_audience' => ['内容运营团队'],
                                    'commercial_scenarios' => ['AI 搜索优化'],
                                    'value_proposition' => '沉淀真实素材并自动生成内容',
                                    'evidence_limits' => '仅来自测试页面',
                                ],
                                'entities' => ['GEO 内容系统', '知识库', '关键词库'],
                                'facts' => ['GEO 内容系统需要知识库、关键词库和标题库协同工作。'],
                                'noise_removed' => [],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'library_name' => 'GEO 内容报告',
                                'knowledge_markdown' => "# GEO 内容报告\n\n- 来源 URL：https://example.test/report\n- 原子化事实：GEO 内容系统需要知识库、关键词库和标题库协同工作。",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode(['keywords' => ['内容资产', '知识库', '标题库', '关键词库']], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode(['titles' => ['GEO 内容系统如何建立可信内容资产', '知识库如何支撑 GEO 内容生成']], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_runner',
            'password' => 'secret-123',
            'email' => 'url-import-runner@example.com',
            'display_name' => 'Url Import Runner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('current_step', 'preview')
            ->assertJsonPath('result_ready', true)
            ->assertJsonPath('progress_percent', 100);

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertStringContainsString('GEO 内容报告', (string) $job->result_json);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'step' => 'keywords',
        ]);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'step' => 'preview',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.commit', ['jobId' => (int) $job->id]))
            ->assertRedirect(route('admin.url-import.show', ['jobId' => (int) $job->id]));

        $this->assertDatabaseHas('knowledge_bases', ['name' => 'GEO 内容报告 知识库']);
        $this->assertDatabaseHas('keyword_libraries', ['name' => 'GEO 内容报告 关键词库']);
        $this->assertDatabaseHas('title_libraries', ['name' => 'GEO 内容报告 标题库']);
        $this->assertDatabaseMissing('image_libraries', ['name' => 'GEO 内容报告 图片库']);
        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'current_step' => 'imported',
        ]);
    }

    public function test_url_import_analysis_prefers_active_ai_model_and_backend_prompts(): void
    {
        Http::fake([
            'https://source.test/report' => Http::response(
                '<!doctype html><html><head><title>原始页面标题</title><meta name="description" content="原始页面摘要"></head><body><article><h1>原始页面标题</h1><p>页面正文包含 CRM、GEO 和知识库信息。</p><img src="/hero.png" alt="GEO 服务主图"></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-clean',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'clean_title' => 'AI清洗标题',
                                'clean_summary' => 'AI 生成的页面摘要，用于描述页面的核心内容和素材价值。',
                                'clean_text' => '页面正文包含 CRM、GEO 和知识库信息。',
                                'entities' => ['CRM', 'GEO', '知识库'],
                                'facts' => ['页面正文包含 CRM、GEO 和知识库信息。'],
                                'noise_removed' => ['导航'],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-knowledge',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'summary' => 'AI 生成的页面摘要，用于描述页面的核心内容和素材价值。',
                                'library_name' => 'AI命名素材',
                                'knowledge_markdown' => "# AI知识库\n\n- 来源真实\n- 可用于 GEO 内容生成",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-keywords',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['keywords' => ['AI关键词一', 'AI关键词二', '查看详情']], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-titles',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['titles' => ['AI生成标题一', 'AI生成标题二']], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        Prompt::query()->create([
            'name' => '关键词提示词',
            'type' => 'keyword',
            'content' => '请提炼关键词',
            'variables' => '',
        ]);
        Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '请生成真实可信内容',
            'variables' => '',
        ]);
        AiModel::query()->create([
            'name' => 'AI Test Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_ai_runner',
            'password' => 'secret-123',
            'email' => 'url-import-ai-runner@example.com',
            'display_name' => 'Url Import AI Runner',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('ai', $result['analysis']['analysis_source'] ?? null);
        $this->assertSame('AI命名素材', $result['analysis']['library_name'] ?? null);
        $this->assertContains('AI关键词一', $result['analysis']['keywords'] ?? []);
        $this->assertNotContains('查看详情', $result['analysis']['keywords'] ?? []);
        $this->assertContains('AI生成标题一', $result['analysis']['titles'] ?? []);
        $this->assertArrayNotHasKey('images', $result['analysis'] ?? []);
    }

    public function test_url_import_accepts_ai_json_wrapped_in_markdown_or_reasoning_text(): void
    {
        Http::fake([
            'https://source.test/wrapped-json' => Http::response(
                '<!doctype html><html><head><title>CRM 业务页</title><meta name="description" content="CRM 业务页摘要"></head><body><article><h1>CRM 业务页</h1><p>面向销售团队的客户数据管理和流程自动化服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => "<think>先分析页面主体。</think>\n```json\n".json_encode([
                    'clean_title' => 'CRM 业务页',
                    'clean_summary' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'clean_text' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['客户数据管理', '流程自动化']],
                    'entities' => ['CRM', '销售团队'],
                    'facts' => ['页面介绍客户数据管理和流程自动化服务。'],
                    'noise_removed' => ['导航'],
                ], JSON_UNESCAPED_UNICODE)."\n```"]]]], 200)
                ->push(['choices' => [['message' => ['content' => "以下是结构化 JSON：\n".json_encode([
                    'summary' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'library_name' => 'CRM 业务知识库',
                    'knowledge_markdown' => "# CRM 业务知识库\n\n- 来源 URL：https://source.test/wrapped-json\n- 服务面向销售团队。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => "```json\n".json_encode(['keywords' => ['客户管理', '销售自动化', 'CRM选型']], JSON_UNESCAPED_UNICODE)."\n```"]]]], 200)
                ->push(['choices' => [['message' => ['content' => "已生成：\n".json_encode(['titles' => ['客户管理系统如何帮助销售团队提升效率']], JSON_UNESCAPED_UNICODE)."\n请查收。"]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_wrapped_json_admin',
            'password' => 'secret-123',
            'email' => 'url-import-wrapped-json@example.com',
            'display_name' => 'Url Import Wrapped Json Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/wrapped-json',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('CRM 业务知识库', $result['analysis']['library_name'] ?? null);
        $this->assertContains('客户管理', $result['analysis']['keywords'] ?? []);
        $this->assertContains('客户管理系统如何帮助销售团队提升效率', $result['analysis']['titles'] ?? []);
    }

    public function test_url_import_accepts_plain_text_lists_from_ai_for_keywords_and_titles(): void
    {
        Http::fake([
            'https://source.test/plain-lists' => Http::response(
                '<!doctype html><html><head><title>CRM 自动化页</title><meta name="description" content="CRM 自动化页摘要"></head><body><article><h1>CRM 自动化页</h1><p>面向中小企业的客户数据统一、销售管道管理和营销自动化服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'CRM 自动化页',
                    'clean_summary' => '面向中小企业的客户数据统一、销售管道管理和营销自动化服务。',
                    'clean_text' => '面向中小企业的客户数据统一、销售管道管理和营销自动化服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['销售管道管理', '营销自动化']],
                    'entities' => ['CRM', '中小企业'],
                    'facts' => ['页面介绍客户数据统一、销售管道管理和营销自动化服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向中小企业的客户数据统一、销售管道管理和营销自动化服务。',
                    'library_name' => 'CRM 自动化知识库',
                    'knowledge_markdown' => "# CRM 自动化知识库\n\n- 面向中小企业。\n- 支持客户数据统一、销售管道管理和营销自动化。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => '智能CRM,营销自动化,销售管道管理,客户数据统一,中小企业CRM']]]], 200)
                ->push(['choices' => [['message' => ['content' => "1. 智能 CRM 如何帮助中小企业统一客户数据\n2. 营销自动化系统怎么提升销售转化\n3. 销售管道管理工具选型要看哪些指标"]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_plain_list_admin',
            'password' => 'secret-123',
            'email' => 'url-import-plain-list@example.com',
            'display_name' => 'Url Import Plain List Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/plain-lists',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertContains('营销自动化', $result['analysis']['keywords'] ?? []);
        $this->assertContains('销售管道管理', $result['analysis']['keywords'] ?? []);
        $this->assertContains('智能 CRM 如何帮助中小企业统一客户数据', $result['analysis']['titles'] ?? []);
    }

    public function test_url_import_fails_over_to_next_available_ai_model(): void
    {
        Http::fake([
            'https://source.test/failover' => Http::response(
                '<!doctype html><html><head><title>GEO 采集页</title><meta name="description" content="GEO 采集页摘要"></head><body><article><h1>GEO 采集页</h1><p>面向企业的内容资产管理服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://bad.test/v1/chat/completions' => Http::response(['detail' => 'API Key 无效'], 401),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'GEO 采集页',
                    'clean_summary' => '面向企业的内容资产管理服务。',
                    'clean_text' => '面向企业的内容资产管理服务。',
                    'core_business' => ['industry' => '内容管理', 'products_services' => ['内容资产管理']],
                    'entities' => ['内容资产管理'],
                    'facts' => ['面向企业的内容资产管理服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向企业的内容资产管理服务。',
                    'library_name' => 'GEO 采集页',
                    'knowledge_markdown' => "# GEO 采集页\n\n- 面向企业的内容资产管理服务。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['keywords' => ['内容资产', '内容管理']], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['titles' => ['内容资产管理如何支撑 GEO 运营']], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_failover_admin',
            'password' => 'secret-123',
            'email' => 'url-import-failover@example.com',
            'display_name' => 'Url Import Failover Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        AiModel::query()->create([
            'name' => 'Bad Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('bad-key'),
            'model_id' => 'bad-chat',
            'model_type' => 'chat',
            'api_url' => 'https://bad.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/failover',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('URL Import AI Model', $result['analysis']['model']['name'] ?? null);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'level' => 'warning',
        ]);
        $this->assertSame(3, UrlImportJobLog::query()
            ->where('job_id', (int) $job->id)
            ->where('level', 'warning')
            ->where('message', 'like', '%Bad Model%')
            ->count());
    }

    public function test_url_import_retries_transient_ai_failure_before_success(): void
    {
        Http::fake([
            'https://source.test/transient' => Http::response(
                '<!doctype html><html><head><title>CRM 增长页</title><meta name="description" content="CRM 增长页摘要"></head><body><article><h1>CRM 增长页</h1><p>面向企业的 CRM 增长服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'temporary upstream error']], 500)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'CRM 增长页',
                    'clean_summary' => '面向企业的 CRM 增长服务。',
                    'clean_text' => '面向企业的 CRM 增长服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['CRM 增长服务']],
                    'entities' => ['CRM 增长服务'],
                    'facts' => ['面向企业的 CRM 增长服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向企业的 CRM 增长服务。',
                    'library_name' => 'CRM 增长页',
                    'knowledge_markdown' => "# CRM 增长页\n\n- 面向企业的 CRM 增长服务。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['keywords' => ['CRM增长', '客户管理']], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['titles' => ['CRM 增长服务如何支撑 GEO 运营']], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_retry_admin',
            'password' => 'secret-123',
            'email' => 'url-import-retry@example.com',
            'display_name' => 'Url Import Retry Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/transient',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('URL Import AI Model', $result['analysis']['model']['name'] ?? null);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'level' => 'warning',
        ]);
    }

    public function test_admin_can_open_all_material_detail_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_detail_admin',
            'password' => 'secret-123',
            'email' => 'materials-detail-admin@example.com',
            'display_name' => 'Materials Detail Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库A',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库A',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库A',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'demo.png',
            'original_name' => 'demo.png',
            'file_name' => 'demo.png',
            'file_path' => 'storage/uploads/images/demo.png',
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 100,
            'height' => 100,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '知识库A',
            'description' => 'desc',
            'content' => '知识内容',
            'character_count' => 4,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 4,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]))
            ->assertOk()
            ->assertSee($keywordLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee($titleLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertSee($imageLibrary->name)
            ->assertSee('storage/uploads/images/demo.png');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_detail.heading'));
    }

    public function test_admin_can_manage_keyword_and_title_details(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_ops_admin',
            'password' => 'secret-123',
            'email' => 'materials-ops-admin@example.com',
            'display_name' => 'Materials Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库B',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库B',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '增长策略',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.titles.store', ['libraryId' => (int) $titleLibrary->id]), [
            'title' => '增长策略完整指南',
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '增长策略完整指南',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.import', ['libraryId' => (int) $titleLibrary->id]), [
            'titles_text' => "标题A|关键词A\n标题B",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '标题A',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $titleLibrary->id]), [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => 1,
            'title_count' => 3,
            'title_style' => 'professional',
            'custom_prompt' => '',
        ])->assertSessionHasErrors();
    }

    public function test_admin_can_upload_image_and_knowledge_file_from_detail_flow(): void
    {
        Storage::fake('public');

        $admin = Admin::query()->create([
            'username' => 'materials_upload_admin',
            'password' => 'secret-123',
            'email' => 'materials-upload-admin@example.com',
            'display_name' => 'Materials Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库C',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        $image = UploadedFile::fake()->image('banner.png', 100, 100);
        $this->actingAs($admin, 'admin')->post(route('admin.image-libraries.images.upload', ['libraryId' => (int) $imageLibrary->id]), [
            'images' => [$image],
        ])->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]));

        $this->assertDatabaseHas('images', [
            'library_id' => (int) $imageLibrary->id,
            'original_name' => 'banner.png',
        ]);

        $storedImage = Image::query()
            ->where('library_id', (int) $imageLibrary->id)
            ->where('original_name', 'banner.png')
            ->firstOrFail();
        $this->assertStringStartsWith('storage/uploads/images/', (string) $storedImage->file_path);
        Storage::disk('public')->assertExists(str_replace('storage/', '', (string) $storedImage->file_path));

        $knowledgeFile = UploadedFile::fake()->createWithContent('manual.md', "# 标题\n内容段落");
        $this->actingAs($admin, 'admin')->post(route('admin.knowledge-bases.upload'), [
            'name' => '上传知识库',
            'description' => '测试上传',
            'knowledge_file' => $knowledgeFile,
        ])->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '上传知识库',
        ]);
    }
}
