<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskRealtimeBroadcastService;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskTransactionSafetyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_task_queue_dispatch_and_broadcast_wait_for_outer_commit(): void
    {
        Queue::fake();
        $broadcastCount = 0;
        $realtime = Mockery::mock(TaskRealtimeBroadcastService::class);
        $realtime->shouldReceive('broadcastOverview')
            ->andReturnUsing(function () use (&$broadcastCount): void {
                $broadcastCount++;
            });
        $this->app->instance(TaskRealtimeBroadcastService::class, $realtime);
        $task = Task::query()->create([
            'name' => 'After commit queue task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $queue = app(JobQueueService::class);

        DB::beginTransaction();
        $rolledBackJobId = $queue->enqueueTaskJob((int) $task->id);
        $this->assertNotNull($rolledBackJobId);
        $pushedBeforeRollback = Queue::pushed(ProcessGeoFlowTaskJob::class)->count();
        $broadcastsBeforeRollback = $broadcastCount;
        DB::rollBack();

        $this->assertSame(0, $pushedBeforeRollback);
        $this->assertSame(0, $broadcastsBeforeRollback);
        Queue::assertNothingPushed();
        $this->assertSame(0, $broadcastCount);

        DB::beginTransaction();
        $committedJobId = $queue->enqueueTaskJob((int) $task->id);
        $this->assertNotNull($committedJobId);
        Queue::assertNothingPushed();
        $this->assertSame(0, $broadcastCount);
        DB::commit();

        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 1);
        $this->assertSame(1, $broadcastCount);
    }

    public function test_recovery_republishes_a_due_stale_pending_run(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Recover stale pending task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'available_at' => now()->subMinutes(20)->toDateTimeString(),
            ],
            'started_at' => null,
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $recovered = app(JobQueueService::class)->recoverStaleJobs(600);

        $this->assertSame(1, $recovered);
        Queue::assertPushed(
            ProcessGeoFlowTaskJob::class,
            fn (ProcessGeoFlowTaskJob $job): bool => $job->taskRunId === (int) $run->id
        );
    }

    public function test_queue_publish_failure_after_commit_leaves_a_persisted_pending_run(): void
    {
        $connection = Mockery::mock(QueueContract::class);
        $connection->shouldReceive('later')
            ->once()
            ->andThrow(new \RuntimeException('redis publish failed'));
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')
            ->once()
            ->andReturn($connection);
        $this->app->instance(QueueFactory::class, $factory);
        $task = Task::query()->create([
            'name' => 'Queue publish failure task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        try {
            app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
            $this->fail('The queue publication failure should escape after commit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('redis publish failed', $exception->getMessage());
        }

        $run = TaskRun::query()->where('task_id', $task->id)->sole();
        $this->assertSame('pending', $run->status);
        $this->assertNotNull($run->created_at);
        $this->assertNull($run->started_at);
        $this->assertNotEmpty($run->meta['available_at'] ?? null);
    }

    public function test_recovery_does_not_republish_a_pending_run_before_its_available_time(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Future pending task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'available_at' => now()->addMinutes(5)->toDateTimeString(),
            ],
            'started_at' => null,
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $recovered = app(JobQueueService::class)->recoverStaleJobs(600);

        $this->assertSame(0, $recovered);
        Queue::assertNothingPushed();
        $this->assertArrayNotHasKey('recovery_dispatched_at', $run->fresh()->meta);
    }

    public function test_recovery_waits_for_the_stale_threshold_after_a_delayed_run_becomes_available(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Fresh pending task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
            'started_at' => null,
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $recovered = app(JobQueueService::class)->recoverStaleJobs(600);

        $this->assertSame(0, $recovered);
        Queue::assertNothingPushed();
        $this->assertArrayNotHasKey('recovery_dispatched_at', $run->fresh()->meta);
    }

    #[DataProvider('inactiveTaskStates')]
    public function test_recovery_cancels_a_stale_pending_run_when_its_task_is_not_executable(string $status, int $scheduleEnabled): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Inactive pending task',
            'status' => $status,
            'schedule_enabled' => $scheduleEnabled,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'available_at' => now()->subMinutes(20)->toDateTimeString(),
            ],
            'started_at' => null,
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $recovered = app(JobQueueService::class)->recoverStaleJobs(600);

        $this->assertSame(0, $recovered);
        Queue::assertNothingPushed();
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    /**
     * @return array<string,array{string,int}>
     */
    public static function inactiveTaskStates(): array
    {
        return [
            'inactive task' => ['paused', 1],
            'disabled schedule' => ['active', 0],
        ];
    }

    public function test_recovery_never_republishes_a_cancelled_run(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Cancelled run task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'cancelled',
            'meta' => [
                'available_at' => now()->subMinutes(20)->toDateTimeString(),
            ],
            'started_at' => null,
            'finished_at' => now()->subMinutes(20),
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $recovered = app(JobQueueService::class)->recoverStaleJobs(600);

        $this->assertSame(0, $recovered);
        Queue::assertNothingPushed();
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_duplicate_recovery_and_queue_delivery_execute_a_pending_run_once(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Duplicate recovery task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'available_at' => now()->subMinutes(20)->toDateTimeString(),
            ],
            'started_at' => null,
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();
        $queue = app(JobQueueService::class);

        $this->assertSame(1, $queue->recoverStaleJobs(600));
        $this->assertSame(0, $queue->recoverStaleJobs(600));
        $this->travel(11)->minutes();
        $this->assertSame(1, $queue->recoverStaleJobs(600));
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 2);

        $worker = Mockery::mock(WorkerExecutionService::class);
        $worker->shouldReceive('executeTask')
            ->once()
            ->with((int) $task->id)
            ->andReturn([
                'article_id' => null,
                'meta' => [],
            ]);
        Queue::pushed(ProcessGeoFlowTaskJob::class)
            ->each(fn (ProcessGeoFlowTaskJob $job) => $job->handle($queue, $worker));

        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_scheduler_republishes_a_due_stale_pending_run_before_busy_checks(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $task = Task::query()->create([
            'name' => 'Scheduler recovery task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now()->subMinute(),
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'available_at' => now()->subMinutes(20)->toDateTimeString(),
            ],
            'started_at' => null,
        ]);
        $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $exitCode = Artisan::call('geoflow:schedule-tasks');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('recovered=1', Artisan::output());
        Queue::assertPushed(
            ProcessGeoFlowTaskJob::class,
            fn (ProcessGeoFlowTaskJob $job): bool => $job->taskRunId === (int) $run->id
        );
    }

    public function test_scheduler_isolates_recovery_publish_failure_and_continues_recovery_and_normal_scheduling(): void
    {
        Exceptions::fake();
        $this->travelTo(now()->startOfMinute());
        $publishedRunIds = [];
        $scheduledRunIds = [];
        $publishAttempt = 0;
        $connection = Mockery::mock(QueueContract::class);
        $connection->shouldReceive('push')
            ->twice()
            ->andReturnUsing(function (...$arguments) use (&$publishAttempt, &$publishedRunIds): string {
                $publishAttempt++;
                /** @var ProcessGeoFlowTaskJob $job */
                $job = $arguments[0];
                if ($publishAttempt === 1) {
                    throw new \RuntimeException('redis recovery publish failed');
                }

                $publishedRunIds[] = $job->taskRunId;

                return 'queued';
            });
        $connection->shouldReceive('later')
            ->once()
            ->andReturnUsing(function (...$arguments) use (&$scheduledRunIds): string {
                /** @var ProcessGeoFlowTaskJob $job */
                $job = $arguments[1];
                $scheduledRunIds[] = $job->taskRunId;

                return 'queued';
            });
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')
            ->times(3)
            ->andReturn($connection);
        $this->app->instance(QueueFactory::class, $factory);

        $runs = collect(['First recovery candidate', 'Second recovery candidate'])
            ->map(function (string $name): TaskRun {
                $task = Task::query()->create([
                    'name' => $name,
                    'status' => 'active',
                    'schedule_enabled' => 1,
                ]);
                $run = TaskRun::query()->create([
                    'task_id' => $task->id,
                    'status' => 'pending',
                    'meta' => [
                        'available_at' => now()->subMinutes(20)->toDateTimeString(),
                    ],
                    'started_at' => null,
                ]);
                $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

                return $run;
            });
        $normalTask = Task::query()->create([
            'name' => 'Normal due scheduler task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now()->subMinute(),
        ]);

        $exitCode = Artisan::call('geoflow:schedule-tasks');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('queued=1', $output);
        $this->assertStringContainsString('recovered=1', $output);
        $this->assertSame([(int) $runs[1]->id], $publishedRunIds);
        $normalRun = TaskRun::query()->where('task_id', $normalTask->id)->sole();
        $this->assertSame([(int) $normalRun->id], $scheduledRunIds);
        $this->assertArrayNotHasKey('recovery_dispatched_at', $runs[0]->fresh()->meta);
        $this->assertArrayHasKey('recovery_dispatched_at', $runs[1]->fresh()->meta);
        Exceptions::assertReported(
            fn (\RuntimeException $exception): bool => $exception->getMessage() === 'redis recovery publish failed'
        );
    }

    public function test_scheduler_isolates_stale_running_publish_failure_and_continues_recovery_and_normal_scheduling(): void
    {
        Exceptions::fake();
        $this->travelTo(now()->startOfMinute());
        $publishedRunIds = [];
        $scheduledRunIds = [];
        $publishAttempt = 0;
        $connection = Mockery::mock(QueueContract::class);
        $connection->shouldReceive('push')
            ->twice()
            ->andReturnUsing(function (...$arguments) use (&$publishAttempt, &$publishedRunIds): string {
                $publishAttempt++;
                /** @var ProcessGeoFlowTaskJob $job */
                $job = $arguments[0];
                if ($publishAttempt === 1) {
                    throw new \RuntimeException('redis running recovery publish failed');
                }

                $publishedRunIds[] = $job->taskRunId;

                return 'queued';
            });
        $connection->shouldReceive('later')
            ->once()
            ->andReturnUsing(function (...$arguments) use (&$scheduledRunIds): string {
                /** @var ProcessGeoFlowTaskJob $job */
                $job = $arguments[1];
                $scheduledRunIds[] = $job->taskRunId;

                return 'queued';
            });
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')
            ->times(3)
            ->andReturn($connection);
        $this->app->instance(QueueFactory::class, $factory);

        $runs = collect(['First stale running candidate', 'Second stale running candidate'])
            ->map(function (string $name): TaskRun {
                $task = Task::query()->create([
                    'name' => $name,
                    'status' => 'active',
                    'schedule_enabled' => 1,
                ]);

                return TaskRun::query()->create([
                    'task_id' => $task->id,
                    'status' => 'running',
                    'meta' => [
                        'available_at' => now()->subMinutes(20)->toDateTimeString(),
                    ],
                    'started_at' => now()->subMinutes(20),
                ]);
            });
        $normalTask = Task::query()->create([
            'name' => 'Normal task after running recovery failure',
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now()->subMinute(),
        ]);

        $exitCode = Artisan::call('geoflow:schedule-tasks');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('queued=1', $output);
        $this->assertStringContainsString('recovered=1', $output);
        $this->assertSame([(int) $runs[1]->id], $publishedRunIds);
        $normalRun = TaskRun::query()->where('task_id', $normalTask->id)->sole();
        $this->assertSame([(int) $normalRun->id], $scheduledRunIds);
        $this->assertSame('pending', $runs[0]->fresh()->status);
        $this->assertArrayNotHasKey('recovery_dispatched_at', $runs[0]->fresh()->meta);
        $this->assertSame('pending', $runs[1]->fresh()->status);
        $this->assertArrayHasKey('recovery_dispatched_at', $runs[1]->fresh()->meta);
        Exceptions::assertReported(
            fn (\RuntimeException $exception): bool => $exception->getMessage() === 'redis running recovery publish failed'
        );
    }
}
