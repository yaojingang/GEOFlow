<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiQualityTaskConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_lifecycle_persists_and_returns_ai_quality_configuration(): void
    {
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
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_90',
        ]);

        $this->assertTrue($task['ai_quality_enabled']);
        $this->assertSame($qualityPrompt->id, $task['ai_quality_prompt_id']);
        $this->assertNull($task['ai_quality_model_id']);
        $this->assertSame(88, $task['ai_quality_pass_score']);
        $this->assertSame(72, $task['ai_quality_manual_override_min_score']);
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
}
