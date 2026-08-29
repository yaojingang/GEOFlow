<?php

namespace Tests\Feature;

use App\Exceptions\TaskTitleReadinessException;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskTitleReadinessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_task_creation_is_rejected_before_any_task_is_written(): void
    {
        $data = $this->taskData($this->library('空库'), ['status' => 'active']);

        try {
            app(TaskLifecycleService::class)->createTask($data);
            $this->fail('An active task with an empty title library should be rejected.');
        } catch (TaskTitleReadinessException $exception) {
            $this->assertSame('task_title_library_not_ready', $exception->getErrorCode());
            $this->assertSame(422, $exception->getHttpStatus());
        }

        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, TaskRun::query()->count());
    }

    public function test_paused_task_can_be_saved_with_an_incomplete_title_library(): void
    {
        $data = $this->taskData($this->library('暂停空库'), ['status' => 'paused']);

        $created = app(TaskLifecycleService::class)->createTask($data);
        $task = Task::query()->findOrFail((int) $created['id']);

        $this->assertSame('paused', $task->status);
        $this->assertSame(0, (int) $task->schedule_enabled);
        $this->assertNull($task->next_run_at);
    }

    public function test_active_update_failure_rolls_back_all_task_changes(): void
    {
        $readyLibrary = $this->library('原标题库', [0]);
        $emptyLibrary = $this->library('新空库');
        $task = Task::query()->create([
            'name' => '修改前名称',
            'title_library_id' => $readyLibrary->id,
            'article_limit' => 1,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);

        try {
            app(TaskLifecycleService::class)->updateTask((int) $task->id, [
                'name' => '不应保存的名称',
                'title_library_id' => (int) $emptyLibrary->id,
                'status' => 'active',
            ]);
            $this->fail('The invalid active update should be rejected.');
        } catch (TaskTitleReadinessException $exception) {
            $this->assertSame(422, $exception->getHttpStatus());
        }

        $fresh = $task->fresh();
        $this->assertSame('修改前名称', $fresh->name);
        $this->assertSame((int) $readyLibrary->id, (int) $fresh->title_library_id);
        $this->assertSame('paused', $fresh->status);
        $this->assertSame(0, (int) $fresh->schedule_enabled);
    }

    public function test_active_update_cannot_remove_the_title_library(): void
    {
        $library = $this->library('可用标题库', [0]);
        $task = Task::query()->create([
            'name' => '活动任务',
            'title_library_id' => $library->id,
            'article_limit' => 1,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        try {
            app(TaskLifecycleService::class)->updateTask((int) $task->id, [
                'title_library_id' => null,
            ]);
            $this->fail('An active task must retain a ready title library.');
        } catch (TaskTitleReadinessException $exception) {
            $this->assertSame('title_library_missing', $exception->getDetails()['title_readiness']['issues'][0]['code']);
        }

        $this->assertSame((int) $library->id, (int) $task->fresh()->title_library_id);
        $this->assertSame('active', $task->fresh()->status);
    }

    public function test_start_and_direct_enqueue_recheck_legacy_task_capacity(): void
    {
        Queue::fake();
        $library = $this->library('遗留空库');
        $paused = Task::query()->create([
            'name' => '暂停任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $legacyActive = Task::query()->create([
            'name' => '遗留活动任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        foreach ([
            fn () => app(TaskLifecycleService::class)->startTask((int) $paused->id, true),
            fn () => app(TaskLifecycleService::class)->enqueueTask((int) $legacyActive->id),
        ] as $action) {
            try {
                $action();
                $this->fail('The title readiness gate should reject this action.');
            } catch (TaskTitleReadinessException $exception) {
                $this->assertSame(409, $exception->getHttpStatus());
            }
        }

        $this->assertSame('paused', $paused->fresh()->status);
        $this->assertSame(0, (int) $paused->fresh()->schedule_enabled);
        $this->assertSame(0, TaskRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_start_and_direct_enqueue_report_a_missing_library_as_not_ready(): void
    {
        Queue::fake();
        $paused = Task::query()->create([
            'name' => '未完整暂停任务',
            'title_library_id' => null,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $legacyActive = Task::query()->create([
            'name' => '未完整活动任务',
            'title_library_id' => null,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        foreach ([
            fn () => app(TaskLifecycleService::class)->startTask((int) $paused->id, true),
            fn () => app(TaskLifecycleService::class)->enqueueTask((int) $legacyActive->id),
        ] as $action) {
            try {
                $action();
                $this->fail('A missing title library should be reported as not ready.');
            } catch (TaskTitleReadinessException $exception) {
                $this->assertSame(409, $exception->getHttpStatus());
                $this->assertSame('title_library_missing', $exception->getDetails()['title_readiness']['issues'][0]['code']);
            }
        }

        $this->assertSame(0, TaskRun::query()->count());
        Queue::assertNothingPushed();
    }

    /** @param list<int> $usedCounts */
    private function library(string $name, array $usedCounts = []): TitleLibrary
    {
        $library = TitleLibrary::query()->create(['name' => $name]);
        foreach ($usedCounts as $index => $usedCount) {
            Title::query()->create([
                'library_id' => $library->id,
                'title' => $name.'标题'.($index + 1),
                'keyword' => $name.'关键词'.($index + 1),
                'used_count' => $usedCount,
            ]);
        }

        return $library;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function taskData(TitleLibrary $library, array $overrides = []): array
    {
        $prompt = Prompt::query()->create([
            'name' => '就绪度提示词',
            'type' => 'content',
            'content' => '请写 {{title}}',
        ]);
        $model = AiModel::query()->create([
            'name' => '就绪度模型',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'readiness-model',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.test/v1',
            'status' => 'active',
        ]);

        return array_merge([
            'name' => '就绪度任务',
            'title_library_id' => (int) $library->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $model->id,
            'article_limit' => 2,
            'draft_limit' => 2,
            'is_loop' => 0,
            'status' => 'paused',
        ], $overrides);
    }
}
