<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiQualityTaskConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_lifecycle_persists_and_returns_ai_quality_configuration(): void
    {
        $this->enableProductionOptimization();
        $contentPrompt = Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '撰写正文',
        ]);
        $qualityPrompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '正文模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'content-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库',
            'description' => '',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '企业知识库',
            'content' => '产品价格为 980 元。',
        ]);

        $task = app(TaskLifecycleService::class)->createTask([
            'name' => '开启质检的任务',
            'title_library_id' => $titleLibrary->id,
            'prompt_id' => $contentPrompt->id,
            'ai_model_id' => $model->id,
            'knowledge_base_ids' => [$knowledgeBase->id],
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $qualityPrompt->id,
            'ai_quality_model_id' => null,
            'ai_quality_pass_score' => 88,
            'ai_quality_manual_override_min_score' => 72,
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_90',
        ]);

        $this->assertTrue($task['ai_quality_enabled']);
        $this->assertSame($qualityPrompt->id, $task['ai_quality_prompt_id']);
        $this->assertNull($task['ai_quality_model_id']);
        $this->assertSame(88, $task['ai_quality_pass_score']);
        $this->assertSame(72, $task['ai_quality_manual_override_min_score']);
        $this->assertSame(AiQualityRetrievalMode::KNOWLEDGE_BROAD, $task['ai_quality_retrieval_mode']);
        $this->assertTrue($task['ai_quality_auto_optimize_enabled']);
        $this->assertSame('excellent_90', $task['ai_quality_optimization_level']);
    }

    public function test_disabling_ai_quality_also_disables_task_auto_optimization(): void
    {
        $task = Task::query()->create([
            'name' => '关闭质检的任务',
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_90',
        ]);

        $updated = app(TaskLifecycleService::class)->updateTask($task->id, [
            'ai_quality_enabled' => false,
        ]);

        $this->assertFalse($updated['ai_quality_enabled']);
        $this->assertFalse($updated['ai_quality_auto_optimize_enabled']);
        $this->assertSame('excellent_90', $updated['ai_quality_optimization_level']);
    }

    public function test_disabled_ai_quality_keeps_retrieval_mode_while_allowing_generation_knowledge_base_changes(): void
    {
        $first = KnowledgeBase::query()->create(['name' => '现有知识库', 'content' => '现有正文']);
        $second = KnowledgeBase::query()->create(['name' => '篡改知识库', 'content' => '篡改正文']);
        $task = Task::query()->create([
            'name' => '关闭质检的配置保护任务',
            'status' => 'paused',
            'ai_quality_enabled' => false,
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::CHUNK,
            'knowledge_base_id' => $first->id,
        ]);
        $task->knowledgeBases()->sync([$first->id => ['sort_order' => 0]]);

        app(TaskLifecycleService::class)->updateTask($task->id, [
            'ai_quality_enabled' => false,
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'knowledge_base_ids' => [$second->id],
        ]);

        $task->refresh();
        $this->assertSame(AiQualityRetrievalMode::CHUNK, $task->ai_quality_retrieval_mode);
        $this->assertSame([$second->id], $task->knowledgeBases()->pluck('knowledge_bases.id')->map('intval')->all());
    }

    public function test_reordering_quality_knowledge_bases_increments_the_policy_version(): void
    {
        $first = KnowledgeBase::query()->create(['name' => '知识库一', 'content' => '正文一']);
        $second = KnowledgeBase::query()->create(['name' => '知识库二', 'content' => '正文二']);
        $task = Task::query()->create([
            'name' => '有序知识库任务',
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'ai_quality_prompt_id' => Prompt::query()
                ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
                ->value('id'),
            'ai_quality_policy_version' => 3,
            'knowledge_base_id' => $first->id,
        ]);
        $task->knowledgeBases()->sync([
            $first->id => ['sort_order' => 0],
            $second->id => ['sort_order' => 1],
        ]);

        app(TaskLifecycleService::class)->updateTask($task->id, [
            'knowledge_base_ids' => [$second->id, $first->id],
        ]);

        $task->refresh();
        $this->assertSame(4, $task->ai_quality_policy_version);
        $this->assertSame(
            [$second->id, $first->id],
            $task->knowledgeBases()->orderByPivot('sort_order')->pluck('knowledge_bases.id')->map('intval')->all(),
        );
    }

    public function test_task_quality_configuration_change_writes_an_append_only_audit_event(): void
    {
        Queue::fake();
        $admin = Admin::query()->create([
            'username' => 'task-quality-auditor',
            'password' => 'secret-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '审计知识库',
            'content' => '产品服务期为一年。',
        ]);
        $model = AiModel::query()->create([
            'name' => '审计模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'audit-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '质检审计任务',
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'ai_quality_policy_version' => 2,
            'ai_model_id' => $model->id,
            'ai_quality_prompt_id' => Prompt::query()
                ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
                ->value('id'),
            'knowledge_base_id' => $knowledgeBase->id,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '策略版本分类', 'slug' => 'policy-version-category']);
        $author = Author::query()->create(['name' => '策略版本作者']);
        $article = Article::query()->create([
            'title' => '任务策略版本传播文章',
            'slug' => 'task-policy-version-propagation',
            'content' => '正文',
            'task_id' => $task->id,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'ai_quality_policy_version' => 7,
        ]);

        app(TaskLifecycleService::class)->updateTask(
            $task->id,
            ['ai_quality_pass_score' => 90],
            auditAdminId: $admin->id,
        );

        $this->assertDatabaseHas('ai_quality_audit_events', [
            'event_type' => 'task_quality_configuration_changed',
            'task_id' => $task->id,
            'admin_id' => $admin->id,
            'policy_version' => 3,
        ]);
        $this->assertSame(8, $article->fresh()->ai_quality_policy_version);
    }

    public function test_task_lifecycle_rejects_an_inverted_quality_threshold_range(): void
    {
        $task = Task::query()->create(['name' => '无效阈值任务']);

        try {
            app(TaskLifecycleService::class)->updateTask($task->id, [
                'ai_quality_pass_score' => 70,
                'ai_quality_manual_override_min_score' => 70,
            ]);
            $this->fail('Expected invalid thresholds to be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('validation_failed', $exception->getErrorCode());
            $this->assertSame(
                '人工放行最低分必须低于自动通过分',
                $exception->getDetails()['field_errors']['ai_quality_manual_override_min_score'],
            );
        }
    }

    public function test_api_quality_configuration_rejects_a_stale_policy_version(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '并发配置知识库',
            'content' => '并发配置核验依据。',
        ]);
        $model = AiModel::query()->create([
            'name' => '并发配置模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'concurrent-config-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '并发配置保护任务',
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_policy_version' => 5,
            'ai_model_id' => $model->id,
            'knowledge_base_id' => $knowledgeBase->id,
            'ai_quality_prompt_id' => Prompt::query()
                ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
                ->value('id'),
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);

        app(TaskLifecycleService::class)->updateTask(
            $task->id,
            ['ai_quality_pass_score' => 88, 'config_version' => 5],
            apiTokenId: 0,
        );

        try {
            app(TaskLifecycleService::class)->updateTask(
                $task->id,
                ['ai_quality_pass_score' => 89, 'config_version' => 5],
                apiTokenId: 0,
            );
            $this->fail('Expected a stale quality configuration version to be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('task_ai_quality_config_version_conflict', $exception->getErrorCode());
            $this->assertSame(6, $exception->getDetails()['current_config_version'] ?? null);
        }

        $this->assertSame(88, $task->fresh()->ai_quality_pass_score);
    }

    public function test_operational_quality_controls_use_the_config_version_without_changing_policy_version(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '运行参数知识库',
            'content' => '服务期为一年。',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => hash('sha256', '服务期为一年。'),
            'chunk_serving_generation' => 'configuration-generation',
            'chunk_serving_source_hash' => hash('sha256', '服务期为一年。'),
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '服务期为一年。',
            'content_hash' => hash('sha256', '服务期为一年。'),
            'source_hash' => hash('sha256', '服务期为一年。'),
            'generation_key' => 'configuration-generation',
        ]);
        $model = AiModel::query()->create([
            'name' => '运行参数模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'operational-config-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '运行参数 CAS 任务',
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::CHUNK,
            'ai_quality_policy_version' => 5,
            'ai_quality_config_version' => 5,
            'ai_model_id' => $model->id,
            'knowledge_base_id' => $knowledgeBase->id,
            'ai_quality_prompt_id' => Prompt::query()
                ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
                ->value('id'),
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);

        app(TaskLifecycleService::class)->updateTask(
            $task->id,
            ['ai_quality_timeout_sampling_enabled' => true, 'config_version' => 5],
            apiTokenId: 1,
        );

        $task->refresh();
        $this->assertSame(5, (int) $task->ai_quality_policy_version);
        $this->assertSame(6, (int) $task->ai_quality_config_version);
        $this->assertTrue((bool) $task->ai_quality_timeout_sampling_enabled);

        try {
            app(TaskLifecycleService::class)->updateTask(
                $task->id,
                ['ai_quality_auto_optimize_enabled' => true, 'config_version' => 5],
                apiTokenId: 1,
            );
            $this->fail('Expected a stale operational configuration version to be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('task_ai_quality_config_version_conflict', $exception->getErrorCode());
            $this->assertSame(6, $exception->getDetails()['current_config_version'] ?? null);
        }
    }

    public function test_production_optimization_requires_both_full_rollouts_and_exposes_legacy_blockers(): void
    {
        $task = $this->qualityTask();
        $service = app(TaskLifecycleService::class);

        foreach ([
            ['geoflow.ai_quality_optimization_enabled' => false],
            ['geoflow.ai_quality_optimization_percent' => 30],
            ['geoflow.ai_quality_optimization_auto_apply_enabled' => false],
            ['geoflow.ai_quality_optimization_auto_apply_percent' => 30],
        ] as $blockedSetting) {
            $this->enableProductionOptimization();
            config($blockedSetting);
            try {
                $service->updateTask($task->id, ['ai_quality_auto_optimize_enabled' => true]);
                $this->fail('A partial rollout must not enable production auto optimization.');
            } catch (ApiException $exception) {
                $this->assertSame(422, $exception->getHttpStatus());
                $this->assertArrayHasKey('ai_quality_auto_optimize_enabled', $exception->getDetails()['field_errors']);
            }
            $task->forceFill(['ai_quality_auto_optimize_enabled' => true])->save();
            $effective = $service->getTask($task->id)['effective_configuration'];
            $this->assertTrue($effective['optimization_requested']);
            $this->assertFalse($effective['optimization_enabled']);
            $this->assertNotEmpty($effective['optimization_blockers']);
            $task->forceFill(['ai_quality_auto_optimize_enabled' => false])->save();
        }
        $this->enableProductionOptimization();
        $updated = $service->updateTask($task->id, ['ai_quality_auto_optimize_enabled' => true]);
        $this->assertTrue($updated['effective_configuration']['optimization_enabled']);
    }

    public function test_broad_sampling_and_optimization_sampling_are_rejected(): void
    {
        $task = $this->qualityTask();
        $this->enableProductionOptimization();
        foreach ([false, true] as $optimize) {
            try {
                app(TaskLifecycleService::class)->updateTask($task->id, [
                    'ai_quality_timeout_sampling_enabled' => true,
                    'ai_quality_auto_optimize_enabled' => $optimize,
                ]);
                $this->fail('Unsupported timeout sampling must be rejected.');
            } catch (ApiException $exception) {
                $this->assertSame(422, $exception->getHttpStatus());
                $this->assertArrayHasKey('ai_quality_timeout_sampling_enabled', $exception->getDetails()['field_errors']);
            }
        }
    }

    public function test_ai_off_preserves_preferences_and_interval_with_effective_optional_checks_disabled(): void
    {
        $task = $this->qualityTask();
        $task->forceFill([
            'need_review' => false,
            'publish_interval' => 900,
            'ai_quality_pass_score' => 91,
            'ai_quality_manual_override_min_score' => 0,
            'ai_quality_optimization_level' => 'excellent_90',
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_timeout_sampling_enabled' => true,
        ])->save();

        $updated = app(TaskLifecycleService::class)->updateTask($task->id, [
            'ai_quality_enabled' => false,
            'category_mode' => 'random',
        ]);

        $this->assertSame(900, $updated['publish_interval']);
        $this->assertSame(91, $updated['ai_quality_pass_score']);
        $this->assertSame(0, $updated['ai_quality_manual_override_min_score']);
        $this->assertSame($task->ai_quality_prompt_id, $updated['ai_quality_prompt_id']);
        $this->assertSame('excellent_90', $updated['ai_quality_optimization_level']);
        $this->assertSame('random', $updated['category_mode']);
        $this->assertFalse($updated['effective_configuration']['optimization_enabled']);
        $this->assertFalse($updated['effective_configuration']['sampling_enabled']);
        $this->assertFalse($updated['effective_configuration']['manual_review_required']);
        $this->assertTrue($updated['effective_configuration']['base_risk_check_enabled']);
    }

    private function enableProductionOptimization(): void
    {
        config([
            'geoflow.ai_quality_optimization_enabled' => true,
            'geoflow.ai_quality_optimization_percent' => 100,
            'geoflow.ai_quality_optimization_auto_apply_enabled' => true,
            'geoflow.ai_quality_optimization_auto_apply_percent' => 100,
        ]);
    }

    private function qualityTask(): Task
    {
        Queue::fake();
        $knowledgeBase = KnowledgeBase::query()->create(['name' => '配置验证知识库', 'content' => '服务期限为一年。']);
        $task = Task::query()->create([
            'name' => '配置真实性任务',
            'status' => 'paused',
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->value('id'),
            'ai_quality_retrieval_mode' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'knowledge_base_id' => $knowledgeBase->id,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);

        return $task;
    }
}
