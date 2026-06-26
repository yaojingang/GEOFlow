<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Models\KnowledgeBase;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Services\GeoFlow\KnowledgeSourceParser;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminEnterpriseKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_admin_can_open_enterprise_knowledge_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.enterprise-knowledge.index'))
            ->assertOk()
            ->assertSee(__('admin.enterprise_knowledge.index_heading'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.enterprise-knowledge.create'))
            ->assertOk()
            ->assertSee(__('admin.enterprise_knowledge.create_heading'))
            ->assertSee('name="enterprise_files[]"', false)
            ->assertSee('multiple', false);
    }

    public function test_admin_can_create_enterprise_knowledge_draft_from_text(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.store'), [
                'name' => '移山科技企业资料',
                'description' => '用于 GEO 内容生成的企业基础资料',
                'content' => "# 企业背景\n移山科技提供 GEO 诊断、内容工程和多站点分发服务。",
            ]);

        $project = EnterpriseKnowledgeProject::query()->where('name', '移山科技企业资料')->firstOrFail();

        $response->assertRedirect(route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]));
        $this->assertSame('queued', (string) $project->status);
        $this->assertSame('', (string) $project->draft_content);
        $this->assertSame('queued', $project->draftGenerationProgress()['step'] ?? '');
        $this->assertDatabaseHas('enterprise_knowledge_sources', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => __('admin.enterprise_knowledge.manual_source_name'),
            'file_type' => 'markdown',
        ]);
        $this->assertDatabaseMissing('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => (int) $project->id,
        ]);
        Queue::assertPushed(GenerateEnterpriseKnowledgeDraftJob::class, fn (GenerateEnterpriseKnowledgeDraftJob $job): bool =>
            $job->projectId === (int) $project->id && $job->adminId === (int) $admin->id
        );
    }

    public function test_create_requires_text_or_uploaded_files(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.enterprise-knowledge.create'))
            ->post(route('admin.enterprise-knowledge.store'), [
                'name' => '空资料',
                'content' => '',
            ])
            ->assertRedirect(route('admin.enterprise-knowledge.create'))
            ->assertSessionHasErrors('content');
    }

    public function test_enterprise_knowledge_generation_job_creates_reviewable_draft(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '异步企业资料',
            'description' => '队列生成测试',
            'status' => 'queued',
            'structured_json' => json_encode([
                'draft_generation' => [
                    'step' => 'queued',
                    'progress' => 8,
                    'message' => 'queued',
                    'started_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'source.md',
            'file_type' => 'markdown',
            'content' => '# 企业背景'."\n".'移山科技提供 GEO 诊断和内容工程服务。',
            'character_count' => 28,
            'sort_order' => 0,
        ]);

        (new GenerateEnterpriseKnowledgeDraftJob((int) $project->id, (int) $admin->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $project->refresh();
        $this->assertSame('reviewing', (string) $project->status);
        $this->assertStringContainsString('企业标准知识稿', (string) $project->draft_content);
        $this->assertStringContainsString('移山科技提供 GEO 诊断和内容工程服务', (string) $project->draft_content);
        $this->assertStringNotContainsString('这个知识库适合回答哪些问题', (string) $project->draft_content);
        $this->assertStringNotContainsString('以下为原始资料摘录', (string) $project->draft_content);
        $this->assertSame('completed', $project->draftGenerationProgress()['step'] ?? '');
        $this->assertSame(100, $project->draftGenerationProgress()['progress'] ?? 0);
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'source' => 'fallback',
            'created_by_admin_id' => (int) $admin->id,
        ]);
    }

    public function test_fallback_draft_is_grounded_in_uploaded_materials(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '星河智能企业资料',
            'description' => '制造业设备巡检资料',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'company-profile.md',
            'file_type' => 'markdown',
            'content' => <<<MARKDOWN
# 星河智能公司资料
星河智能为制造企业提供设备巡检 SaaS 和维保工单系统。

## 产品能力
- 设备告警
- 维保工单
- 传感器数据接入

## 应用场景
面向产线巡检、异常预警和维修协同。

## FAQ
Q: 支持哪些设备？
A: 支持产线 PLC 和传感器数据接入。
MARKDOWN,
            'character_count' => 154,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('fallback', $result['source']);
        $this->assertStringContainsString('星河智能为制造企业提供设备巡检 SaaS', $result['content']);
        $this->assertStringContainsString('设备告警', $result['content']);
        $this->assertStringContainsString('产线巡检', $result['content']);
        $this->assertStringContainsString('支持哪些设备', $result['content']);
        $this->assertStringNotContainsString('company-profile.md', $result['content']);
        $this->assertStringNotContainsString('资料覆盖清单', $result['content']);
        $this->assertStringNotContainsString('来源数量', $result['content']);
        $this->assertStringNotContainsString('154 字', $result['content']);
        $this->assertStringNotContainsString('证据与来源', $result['content']);
        $this->assertStringNotContainsString('自动补全与纠错记录', $result['content']);
        $this->assertStringNotContainsString('这个知识库适合回答哪些问题', $result['content']);
        $this->assertStringNotContainsString('以下为原始资料摘录', $result['content']);
        $this->assertStringNotContainsString('当前为稳定回退草稿', $result['content']);
    }

    public function test_enterprise_knowledge_draft_generation_does_not_require_embedding_model(): void
    {
        $admin = $this->admin();
        AiModel::query()->create([
            'name' => 'Embedding Only',
            'version' => 'v1',
            'api_key' => 'unused',
            'model_id' => 'text-embedding-v4',
            'model_type' => 'embedding',
            'api_url' => 'https://example.com/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '只有向量模型的企业资料',
            'description' => '初稿整理不依赖向量化',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'profile.md',
            'file_type' => 'markdown',
            'content' => '# 企业背景'."\n".'移山科技提供 GEO 内容工程、知识库整理和多站点分发服务。',
            'character_count' => 36,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('fallback', $result['source']);
        $this->assertNull($result['model_id']);
        $this->assertStringContainsString('业务信息摘要', $result['content']);
        $this->assertStringContainsString('移山科技提供 GEO 内容工程', $result['content']);
    }

    public function test_enterprise_knowledge_draft_generation_uses_chat_model_without_embedding_model(): void
    {
        $admin = $this->admin();
        $model = AiModel::query()->create([
            'name' => 'Chat Only',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        Http::fake([
            'https://ai.test/*' => Http::response($this->chatCompletion($this->completeDraftContent('AI 结构化企业知识稿')), 200),
        ]);

        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '聊天模型企业资料',
            'description' => '初稿整理只需要聊天模型',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'profile.md',
            'file_type' => 'markdown',
            'content' => '# 企业背景'."\n".'移山科技提供 GEO 内容工程、知识库整理和多站点分发服务。',
            'character_count' => 36,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('ai', $result['source']);
        $this->assertSame((int) $model->id, $result['model_id']);
        $this->assertStringContainsString('AI 结构化企业知识稿', $result['content']);
        $this->assertStringContainsString('业务信息摘要', $result['content']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/chat/completions'));
    }

    public function test_ai_draft_generation_backfills_source_details_missing_from_model_output(): void
    {
        $admin = $this->admin();
        AiModel::query()->create([
            'name' => 'Detail Preserving Chat',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        Http::fake([
            'https://ai.test/*' => Http::response($this->chatCompletion($this->completeDraftContent('AI 简化草稿')), 200),
        ]);

        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '细节保留企业资料',
            'description' => '测试 AI 简化输出后的本地补录',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'detail-profile.md',
            'file_type' => 'markdown',
            'content' => <<<MARKDOWN
# 企业背景
澜舟数据为连锁零售企业提供门店知识库、巡店 SOP 和总部督导协同系统。

## 产品能力
- 支持私有化 SSO、区域权限隔离和三层审批流。
- 支持离线巡店表单、异常照片留痕和夜间自动汇总。

## 应用场景
夜间巡检、门店整改复盘、区域经理跨城督导和新人培训都需要保留在知识库中。

## FAQ
Q: 是否支持离线填报？
A: 支持离线填报，联网后自动同步到总部后台。
MARKDOWN,
            'character_count' => 220,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('ai', $result['source']);
        $this->assertStringContainsString('### 补充细节', $result['content']);
        $this->assertStringContainsString('支持私有化 SSO、区域权限隔离和三层审批流', $result['content']);
        $this->assertStringContainsString('支持离线巡店表单、异常照片留痕和夜间自动汇总', $result['content']);
        $this->assertStringContainsString('夜间巡检、门店整改复盘、区域经理跨城督导和新人培训', $result['content']);
        $this->assertStringContainsString('支持离线填报，联网后自动同步到总部后台', $result['content']);
        $this->assertStringNotContainsString('detail-profile.md', $result['content']);
    }

    public function test_fallback_draft_preserves_late_details_from_long_sources(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '长资料企业知识稿',
            'description' => '测试长资料尾部信息保留',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        $details = [];
        for ($index = 1; $index <= 90; $index++) {
            $details[] = "第 {$index} 条产品能力：支持区域 {$index} 的门店数据采集、异常处理、复盘记录和负责人追踪。";
        }
        $details[] = '尾部关键能力：支持跨区域 SLA 报表导出、夜间异常复盘和督导责任链追踪。';
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'long-profile.md',
            'file_type' => 'markdown',
            'content' => "# 长资料\n".implode("\n", $details),
            'character_count' => 8000,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('fallback', $result['source']);
        $this->assertStringContainsString('第 1 条产品能力', $result['content']);
        $this->assertStringContainsString('第 90 条产品能力', $result['content']);
        $this->assertStringContainsString('尾部关键能力：支持跨区域 SLA 报表导出、夜间异常复盘和督导责任链追踪', $result['content']);
        $this->assertStringNotContainsString('long-profile.md', $result['content']);
    }

    public function test_enterprise_knowledge_draft_generation_rejects_ai_source_numbering_noise(): void
    {
        $admin = $this->admin();
        AiModel::query()->create([
            'name' => 'Noisy Chat',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $noisyContent = $this->completeDraftContent('带来源噪音 AI 草稿')
            ."\n\n## 资料覆盖清单\n- [1] profile.md（markdown，36 字）";
        Http::fake([
            'https://ai.test/*' => Http::response($this->chatCompletion($noisyContent), 200),
        ]);

        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '来源编号噪音企业资料',
            'description' => 'AI 输出完整但夹带来源噪音时需要回退',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'profile.md',
            'file_type' => 'markdown',
            'content' => '# 企业背景'."\n".'移山科技提供 GEO 内容工程、知识库整理和多站点分发服务。',
            'character_count' => 36,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('fallback', $result['source']);
        $this->assertNull($result['model_id']);
        $this->assertStringContainsString('AI 输出包含来源编号或处理噪音', (string) $result['error']);
        $this->assertStringContainsString('移山科技提供 GEO 内容工程', $result['content']);
        $this->assertStringNotContainsString('资料覆盖清单', $result['content']);
        $this->assertStringNotContainsString('[1]', $result['content']);
        $this->assertStringNotContainsString('36 字', $result['content']);
    }

    public function test_enterprise_knowledge_draft_generation_falls_back_when_ai_response_is_incomplete(): void
    {
        $admin = $this->admin();
        AiModel::query()->create([
            'name' => 'Incomplete Chat',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        Http::fake([
            'https://ai.test/*' => Http::response($this->chatCompletion("# 企业介绍\n独有内容治理平台。"), 200),
        ]);

        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '结构不完整企业资料',
            'description' => 'AI 输出结构缺失时需要稳定回退',
            'status' => 'queued',
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'profile.md',
            'file_type' => 'markdown',
            'content' => '# 企业介绍'."\n".'独有内容治理平台，支持内容健康检查、结构化小标题和证据管理。',
            'character_count' => 42,
            'sort_order' => 0,
        ]);

        $result = app(EnterpriseKnowledgeDraftService::class)->generateDraft($project->fresh(['sources']));

        $this->assertSame('fallback', $result['source']);
        $this->assertNull($result['model_id']);
        $this->assertStringContainsString('独有内容治理平台', $result['content']);
        $this->assertStringContainsString('业务信息摘要', $result['content']);
        $this->assertStringContainsString('AI 输出缺少必要结构化章节', (string) $result['error']);
    }

    public function test_enterprise_knowledge_status_endpoint_returns_generation_progress(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '进度查询项目',
            'status' => 'processing',
            'structured_json' => json_encode([
                'draft_generation' => [
                    'step' => 'cleaning',
                    'progress' => 35,
                    'message' => '正在清洗正文',
                    'updated_at' => now()->toIso8601String(),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.enterprise-knowledge.status', ['projectId' => (int) $project->id]))
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('draft_ready', false)
            ->assertJsonPath('progress.step', 'cleaning')
            ->assertJsonPath('progress.progress', 35)
            ->assertJsonPath('progress.message', '正在清洗正文');
    }

    public function test_enterprise_knowledge_status_endpoint_handles_unknown_status_without_translation_key(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '未知状态项目',
            'status' => 'paused_by_admin',
            'structured_json' => json_encode([
                'draft_generation' => [
                    'step' => 'custom_step',
                    'progress' => 12,
                    'updated_at' => now()->toIso8601String(),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.enterprise-knowledge.status', ['projectId' => (int) $project->id]))
            ->assertOk()
            ->assertJsonPath('status', 'paused_by_admin')
            ->assertJsonPath('status_label', 'paused_by_admin')
            ->assertJsonPath('progress.step', 'custom_step')
            ->assertJsonPath('progress.progress', 12)
            ->assertJsonPath('progress.message', __('admin.enterprise_knowledge.progress_message.queued'));
    }

    public function test_show_page_renders_generation_progress_for_queued_project(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '等待生成项目',
            'status' => 'queued',
            'structured_json' => json_encode([
                'draft_generation' => [
                    'step' => 'queued',
                    'progress' => 8,
                    'message' => __('admin.enterprise_knowledge.progress_message.queued'),
                    'updated_at' => now()->toIso8601String(),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'source.md',
            'file_type' => 'markdown',
            'content' => '# 来源资料',
            'character_count' => 6,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]))
            ->assertOk()
            ->assertSee('id="enterprise-progress-panel"', false)
            ->assertSee(__('admin.enterprise_knowledge.progress_waiting_editor'))
            ->assertDontSee('id="enterprise-draft-content"', false);
    }

    public function test_uploaded_files_are_stored_and_cleaned_when_project_is_deleted(): void
    {
        Queue::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.store'), [
                'name' => '文件企业资料',
                'enterprise_files' => [
                    UploadedFile::fake()->createWithContent('company.md', "# 公司资料\n产品能力和案例资料。"),
                ],
            ]);

        $project = EnterpriseKnowledgeProject::query()->where('name', '文件企业资料')->firstOrFail();
        $response->assertRedirect(route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]));

        $source = EnterpriseKnowledgeSource::query()
            ->where('enterprise_knowledge_project_id', (int) $project->id)
            ->where('original_name', 'company.md')
            ->firstOrFail();
        $this->assertNotSame('', (string) $source->file_path);
        Storage::disk('local')->assertExists((string) $source->file_path);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.delete', ['projectId' => (int) $project->id]))
            ->assertRedirect(route('admin.enterprise-knowledge.index'));

        Storage::disk('local')->assertMissing((string) $source->file_path);
        $this->assertDatabaseMissing('enterprise_knowledge_projects', ['id' => (int) $project->id]);
    }

    public function test_markdown_extension_upload_is_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.store'), [
                'name' => 'Markdown 扩展名资料',
                'enterprise_files' => [
                    UploadedFile::fake()->createWithContent('company-profile.markdown', "# 公司资料\n企业介绍、产品能力和 FAQ。"),
                ],
            ]);

        $project = EnterpriseKnowledgeProject::query()->where('name', 'Markdown 扩展名资料')->firstOrFail();
        $response->assertRedirect(route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]));

        $this->assertDatabaseHas('enterprise_knowledge_sources', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'company-profile.markdown',
            'file_type' => 'markdown',
        ]);
    }

    public function test_enterprise_knowledge_file_cleanup_ignores_paths_outside_upload_directories(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('knowledge-bases/legacy.md', '# legacy');
        Storage::disk('local')->put('uploads/enterprise-knowledge/remove.md', '# remove');
        Storage::disk('local')->put('outside.md', '# keep');

        $parser = app(KnowledgeSourceParser::class);
        $parser->deleteKnowledgeFilePath('../outside.md');
        $parser->deleteKnowledgeFilePath('/uploads/enterprise-knowledge/remove.md');
        $parser->deleteKnowledgeFilePath('outside.md');

        Storage::disk('local')->assertExists('uploads/enterprise-knowledge/remove.md');
        Storage::disk('local')->assertExists('outside.md');

        $parser->deleteKnowledgeFilePath('uploads/enterprise-knowledge/remove.md');
        $parser->deleteKnowledgeFilePath('knowledge-bases/legacy.md');

        Storage::disk('local')->assertMissing('uploads/enterprise-knowledge/remove.md');
        Storage::disk('local')->assertMissing('knowledge-bases/legacy.md');
        Storage::disk('local')->assertExists('outside.md');
    }

    public function test_show_page_renders_editor_sources_and_revisions(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '企业资料编辑项目',
            'description' => '用于检查编辑页渲染',
            'status' => 'reviewing',
            'draft_content' => $this->completeDraftContent('编辑页草稿'),
            'validation_json' => json_encode([
                ['level' => 'info', 'section' => '企业介绍', 'message' => '请复核企业定位。'],
            ], JSON_UNESCAPED_UNICODE),
            'created_by_admin_id' => (int) $admin->id,
        ]);
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'original_name' => 'source.md',
            'file_type' => 'markdown',
            'content' => '# 来源资料',
            'character_count' => 6,
            'sort_order' => 0,
        ]);
        EnterpriseKnowledgeRevision::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'content' => $this->completeDraftContent('编辑页修订'),
            'source' => 'manual',
            'summary' => '人工保存版本',
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]))
            ->assertOk()
            ->assertSee('企业资料编辑项目')
            ->assertSee('id="enterprise-draft-content"', false)
            ->assertSee('id="enterprise-draft-editor"', false)
            ->assertSee('href="#validation-section"', false)
            ->assertSee('id="validation-section"', false)
            ->assertSee('href="#revision-section"', false)
            ->assertSee('id="revision-section"', false)
            ->assertSee("mode: 'wysiwyg'", false)
            ->assertSee('vendor/vditor/dist/index.min.js', false)
            ->assertSee(route('admin.enterprise-knowledge.editor.images.upload', ['projectId' => (int) $project->id], false), false)
            ->assertSee('source.md')
            ->assertSee('人工保存版本');
    }

    public function test_editor_image_upload_returns_markdown_asset(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '图片上传项目',
            'status' => 'reviewing',
            'draft_content' => $this->completeDraftContent('图片上传草稿'),
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.editor.images.upload', ['projectId' => (int) $project->id]), [
                'image' => UploadedFile::fake()->image('product.png', 640, 360),
                'alt' => '产品截图',
            ], [
                'Accept' => 'application/json',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', __('admin.enterprise_knowledge.editor_upload_success'))
            ->assertJsonPath('image.alt', '产品截图');

        $payload = $response->json('image');
        $this->assertIsArray($payload);
        $this->assertStringContainsString('![产品截图](', (string) ($payload['markdown'] ?? ''));
        $this->assertStringContainsString('/storage/uploads/enterprise-knowledge/'.(int) $project->id.'/', (string) ($payload['url'] ?? ''));
        Storage::disk('public')->assertExists((string) ($payload['storage_path'] ?? ''));
    }

    public function test_editor_autosave_updates_draft_and_records_revision(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '自动保存项目',
            'status' => 'reviewing',
            'draft_content' => '# 旧内容',
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $content = $this->completeDraftContent('自动保存后的企业知识稿');

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.enterprise-knowledge.autosave', ['projectId' => (int) $project->id]), [
                'content' => $content,
            ])
            ->assertOk()
            ->assertJsonStructure(['saved_at', 'validation_count', 'validation_items']);

        $this->assertSame($content, (string) $project->fresh()->draft_content);
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'source' => 'manual',
        ]);
    }

    public function test_publish_creates_formal_knowledge_base_and_records_relation(): void
    {
        $admin = $this->admin();
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '正式企业知识库',
            'description' => '审核后的企业资料',
            'status' => 'reviewing',
            'draft_content' => $this->completeDraftContent('正式发布内容'),
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $this->mock(KnowledgeChunkSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')->once()->andReturn(8);
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.publish', ['projectId' => (int) $project->id]))
            ->assertRedirect();

        $knowledgeBase = KnowledgeBase::query()->where('name', '正式企业知识库')->firstOrFail();
        $project->refresh();

        $this->assertSame('published', (string) $project->status);
        $this->assertSame((int) $knowledgeBase->id, (int) $project->published_knowledge_base_id);
        $this->assertStringContainsString('正式发布内容', (string) $knowledgeBase->content);
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'source' => 'publish',
        ]);
    }

    public function test_publish_reuses_existing_formal_knowledge_base(): void
    {
        $admin = $this->admin();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '已发布企业知识库',
            'description' => '旧说明',
            'content' => '# 旧内容',
            'character_count' => 5,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 5,
            'usage_count' => 0,
        ]);
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => '已发布企业知识库',
            'description' => '更新后的说明',
            'status' => 'published',
            'draft_content' => $this->completeDraftContent('二次发布内容'),
            'published_knowledge_base_id' => (int) $knowledgeBase->id,
            'created_by_admin_id' => (int) $admin->id,
        ]);

        $this->mock(KnowledgeChunkSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')->once()->andReturn(9);
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.enterprise-knowledge.publish', ['projectId' => (int) $project->id]))
            ->assertRedirect();

        $project->refresh();
        $knowledgeBase->refresh();

        $this->assertSame((int) $knowledgeBase->id, (int) $project->published_knowledge_base_id);
        $this->assertSame(1, KnowledgeBase::query()->where('name', '已发布企业知识库')->count());
        $this->assertSame('更新后的说明', (string) $knowledgeBase->description);
        $this->assertStringContainsString('二次发布内容', (string) $knowledgeBase->content);
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'source' => 'publish',
        ]);
    }

    public function test_materials_page_links_to_enterprise_knowledge_builder(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.knowledge_hub_enterprise'))
            ->assertSee(route('admin.enterprise-knowledge.create'), false);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'enterprise_knowledge_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid('enterprise-knowledge-admin-').'@example.com',
            'display_name' => 'Enterprise Knowledge Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'test-chat-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];
    }

    private function completeDraftContent(string $prefix): string
    {
        return <<<MARKDOWN
# {$prefix}

## 企业介绍
移山科技围绕 GEO 内容工程提供诊断、知识库、内容生产和多站点分发能力。

## 业务信息摘要
- 移山科技的企业知识稿用于支持 GEO 内容工程、AI 搜索可见性建设和多渠道内容管理。
- 正式发布前需要人工确认客户案例、效果数据、价格、资质和公开引用范围。

## 产品能力
- GEO 诊断报告
- 企业知识库整理
- 内容自动生成与审核

## 应用场景
- 企业官网内容升级
- AI 搜索可见性建设
- 多渠道内容分发管理

## 典型案例
- 待补充：仅填写已核验案例。

## FAQ
### 这套资料能回答什么问题？
可以回答企业定位、服务范围、产品能力、适用场景和风险边界。

## 禁用表述
- 不使用绝对化承诺。

## 风险与冲突
- 涉及效果承诺、价格和资质的信息需要人工复核。

## 待人工确认
- 所有客户案例、效果数据和公开引用范围需要人工确认。
MARKDOWN;
    }
}
