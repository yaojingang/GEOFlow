<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskTitleReadinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskTitleReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_library_blocks_activation_but_allows_a_paused_task_to_be_saved(): void
    {
        $library = TitleLibrary::query()->create(['name' => '空标题库']);

        $activeReport = app(TaskTitleReadinessService::class)->inspect(
            (int) $library->id,
            3,
            false,
            'active',
        );
        $pausedReport = app(TaskTitleReadinessService::class)->inspect(
            (int) $library->id,
            3,
            false,
            'paused',
        );

        $this->assertSame('blocked', $activeReport['status']);
        $this->assertFalse($activeReport['can_save']);
        $this->assertFalse($activeReport['can_activate']);
        $this->assertSame('title_library_empty', $activeReport['issues'][0]['code']);
        $this->assertTrue($pausedReport['can_save']);
        $this->assertFalse($pausedReport['can_activate']);
        $this->assertTrue($pausedReport['requires_acknowledgement']);
    }

    public function test_non_loop_task_reports_exhausted_and_partial_shortage_capacity(): void
    {
        $exhausted = $this->createLibrary('已耗尽', [1, 2]);
        $partial = $this->createLibrary('部分可用', [0, 1, null]);

        $exhaustedReport = $this->inspect($exhausted, 2);
        $partialReport = $this->inspect($partial, 4);

        $this->assertSame('title_library_exhausted', $exhaustedReport['issues'][0]['code']);
        $this->assertSame(2, $exhaustedReport['shortage']);
        $this->assertSame(0, $exhaustedReport['suggested_article_limit']);
        $this->assertSame('title_library_shortage', $partialReport['issues'][0]['code']);
        $this->assertSame(2, $partialReport['library']['available']);
        $this->assertSame(2, $partialReport['shortage']);
        $this->assertSame(2, $partialReport['suggested_article_limit']);
    }

    public function test_non_loop_task_with_enough_titles_is_ready_and_null_usage_counts_as_available(): void
    {
        $library = $this->createLibrary('就绪标题库', [null, 0, 2]);

        $report = $this->inspect($library, 2);

        $this->assertSame('ready', $report['status']);
        $this->assertTrue($report['can_activate']);
        $this->assertFalse($report['requires_acknowledgement']);
        $this->assertSame(['total' => 3, 'used' => 1, 'available' => 2], [
            'total' => $report['library']['total'],
            'used' => $report['library']['used'],
            'available' => $report['library']['available'],
        ]);
    }

    public function test_loop_task_can_activate_and_reports_that_used_titles_will_be_reused(): void
    {
        $library = $this->createLibrary('循环标题库', [3, 1]);

        $report = app(TaskTitleReadinessService::class)->inspect(
            (int) $library->id,
            8,
            true,
            'active',
        );

        $this->assertSame('warning', $report['status']);
        $this->assertTrue($report['can_save']);
        $this->assertTrue($report['can_activate']);
        $this->assertSame(0, $report['shortage']);
        $this->assertSame('loop_reuses_titles', $report['issues'][0]['code']);
    }

    public function test_edit_report_reads_created_count_from_database_for_remaining_demand(): void
    {
        $library = $this->createLibrary('编辑标题库', [0, 0, 1]);
        $task = Task::query()->create([
            'name' => '已有进度任务',
            'title_library_id' => $library->id,
            'article_limit' => 9,
            'created_count' => 4,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);

        $report = app(TaskTitleReadinessService::class)->inspect(
            (int) $library->id,
            7,
            false,
            'active',
            (int) $task->id,
        );

        $this->assertSame(4, $report['task']['created_count']);
        $this->assertSame(3, $report['task']['remaining']);
        $this->assertSame(1, $report['shortage']);
        $this->assertSame(6, $report['suggested_article_limit']);
    }

    public function test_active_task_using_the_same_library_is_a_confirmable_warning(): void
    {
        $library = $this->createLibrary('共享标题库', [0, 0, 0]);
        $otherTask = Task::query()->create([
            'name' => '另一个运行中任务',
            'title_library_id' => $library->id,
            'article_limit' => 5,
            'created_count' => 2,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        $report = $this->inspect($library, 2);

        $this->assertSame('warning', $report['status']);
        $this->assertTrue($report['can_save']);
        $this->assertTrue($report['can_activate']);
        $this->assertTrue($report['requires_acknowledgement']);
        $this->assertSame('title_library_shared', $report['issues'][0]['code']);
        $this->assertSame((int) $otherTask->id, $report['conflicts'][0]['id']);
        $this->assertSame(3, $report['conflicts'][0]['remaining']);
    }

    public function test_missing_library_returns_the_same_structured_blocking_report(): void
    {
        $task = Task::query()->create([
            'name' => '未完整任务',
            'title_library_id' => null,
            'article_limit' => 5,
            'created_count' => 2,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);

        $report = app(TaskTitleReadinessService::class)->inspectTask($task, 'active');

        $this->assertSame('blocked', $report['status']);
        $this->assertFalse($report['can_activate']);
        $this->assertSame('title_library_missing', $report['issues'][0]['code']);
        $this->assertSame(3, $report['shortage']);
        $this->assertSame(2, $report['suggested_article_limit']);
    }

    public function test_conflict_details_are_bounded_while_the_total_count_is_preserved(): void
    {
        $library = $this->createLibrary('大量共享任务', [0]);
        foreach (range(1, 12) as $index) {
            Task::query()->create([
                'name' => '共享任务'.$index,
                'title_library_id' => $library->id,
                'article_limit' => 2,
                'created_count' => 0,
                'status' => 'active',
                'schedule_enabled' => 1,
            ]);
        }

        $report = $this->inspect($library, 1);

        $this->assertSame(12, $report['conflict_count']);
        $this->assertCount(10, $report['conflicts']);
        $this->assertSame('title_library_shared', $report['issues'][0]['code']);
    }

    /** @param list<int|null> $usedCounts */
    private function createLibrary(string $name, array $usedCounts): TitleLibrary
    {
        if (in_array(null, $usedCounts, true)) {
            Schema::table('titles', function (Blueprint $table): void {
                $table->integer('used_count')->nullable()->change();
            });
        }

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

    /** @return array<string, mixed> */
    private function inspect(TitleLibrary $library, int $articleLimit): array
    {
        return app(TaskTitleReadinessService::class)->inspect(
            (int) $library->id,
            $articleLimit,
            false,
            'active',
        );
    }
}
