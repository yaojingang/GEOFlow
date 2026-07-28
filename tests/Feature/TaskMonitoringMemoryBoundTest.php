<?php

namespace Tests\Feature;

use App\Events\Admin\TasksOverviewUpdated;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskMonitoringMemoryBoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_hydrates_only_the_latest_run_for_each_task(): void
    {
        $task = Task::query()->create([
            'name' => '长历史任务',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        foreach (range(1, 80) as $index) {
            TaskRun::query()->create([
                'task_id' => $task->id,
                'status' => $index === 80 ? 'failed' : 'completed',
                'error_message' => $index === 80 ? '最后一次失败' : '',
                'meta' => [],
                'finished_at' => now(),
            ]);
        }

        $retrievedRuns = 0;
        Event::listen('eloquent.retrieved: '.TaskRun::class, function () use (&$retrievedRuns): void {
            $retrievedRuns++;
        });

        $snapshot = app(TaskMonitoringQueryService::class)->buildTaskSnapshot();

        $this->assertCount(1, $snapshot);
        $this->assertSame('failed', $snapshot[0]['latest_job_status']);
        $this->assertSame('最后一次失败', $snapshot[0]['batch_error_message']);
        $this->assertLessThanOrEqual(2, $retrievedRuns);
    }

    public function test_worker_overview_marks_old_heartbeats_as_stale_and_exposes_memory(): void
    {
        if (! Schema::hasTable('worker_heartbeats')) {
            Schema::create('worker_heartbeats', function (Blueprint $table): void {
                $table->string('worker_id')->primary();
                $table->string('status', 20);
                $table->timestamp('last_seen_at')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
            });
        }

        DB::table('worker_heartbeats')->insert([
            'worker_id' => 'worker-stale',
            'status' => 'running',
            'last_seen_at' => now()->subMinutes(5),
            'meta' => json_encode([
                'task_run_id' => 77,
                'memory_mb' => 96.5,
                'peak_memory_mb' => 112.25,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $worker = app(TaskMonitoringQueryService::class)
            ->buildAdminOverview()['worker_overview'][0];

        $this->assertSame('stale', $worker['status']);
        $this->assertTrue($worker['is_stale']);
        $this->assertSame(77, $worker['current_job_id']);
        $this->assertSame(96.5, $worker['memory_mb']);
        $this->assertSame(112.25, $worker['peak_memory_mb']);
    }

    public function test_admin_overview_and_snapshot_keep_task_hydration_bounded(): void
    {
        foreach (range(1, 130) as $index) {
            Task::query()->create([
                'name' => '有界任务 '.$index,
                'status' => $index % 2 === 0 ? 'active' : 'paused',
                'schedule_enabled' => 1,
            ]);
        }

        $service = app(TaskMonitoringQueryService::class);
        $overview = $service->buildAdminOverview(2, 50);
        $snapshot = $service->buildTaskSnapshot();

        $this->assertCount(50, $overview['tasks']);
        $this->assertSame(2, $overview['pagination']['page']);
        $this->assertSame(130, $overview['pagination']['total']);
        $this->assertSame(3, $overview['pagination']['total_pages']);
        $this->assertSame(130, $overview['task_summary']['total_tasks']);
        $this->assertSame(65, $overview['task_summary']['enabled_tasks']);
        $this->assertCount(100, $snapshot);
    }

    public function test_realtime_event_contains_only_a_lightweight_refresh_signal(): void
    {
        $payload = (new TasksOverviewUpdated('2026-07-28T12:00:00+08:00'))->broadcastWith();

        $this->assertSame([
            'refresh_required' => true,
            'changed_at' => '2026-07-28T12:00:00+08:00',
        ], $payload);
        $this->assertArrayNotHasKey('tasks', $payload);
    }

    public function test_scheduler_processes_large_task_sets_in_bounded_batches(): void
    {
        Queue::fake();
        foreach (range(1, 205) as $index) {
            Task::query()->create([
                'name' => '批量调度任务 '.$index,
                'status' => 'active',
                'schedule_enabled' => 1,
                'next_run_at' => null,
            ]);
        }

        $this->artisan('geoflow:schedule-tasks')
            ->expectsOutputToContain('skipped=205')
            ->assertSuccessful();

        $this->assertSame(
            205,
            Task::query()->whereNotNull('next_run_at')->count()
        );
    }
}
