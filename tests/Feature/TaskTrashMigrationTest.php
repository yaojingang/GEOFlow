<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskTrashMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_backfill_covers_multiple_chunks_in_deletion_order(): void
    {
        $sequenceMigration = require database_path('migrations/2026_08_27_040000_add_serialized_task_trash_sequence.php');
        $sequenceMigration->down();

        $baseDeletedAt = Carbon::parse('2026-08-27 12:00:00.123456');
        $taskIds = range(500_000, 502_000);
        foreach (array_chunk($taskIds, 400) as $taskIdChunk) {
            DB::table('tasks')->insert(array_map(static fn (int $taskId): array => [
                'id' => $taskId,
                'name' => 'trash migration '.$taskId,
                'status' => 'paused',
                'created_at' => '2026-08-27 12:00:00.123456',
                'updated_at' => '2026-08-27 12:00:00.123456',
                'deleted_at' => '2026-08-27 12:00:00.123456',
            ], $taskIdChunk));
        }
        foreach (array_chunk(array_reverse($taskIds), 400) as $taskIdChunk) {
            DB::table('task_trash_entries')->insert(array_map(static fn (int $taskId): array => [
                'task_id' => $taskId,
                'deleted_at' => $baseDeletedAt->copy()->addSeconds($taskId - 500_000)->format('Y-m-d H:i:s.u'),
            ], $taskIdChunk));
        }

        $sequenceMigration->up();

        $this->assertSame(2001, DB::table('task_trash_entries')->count());
        $this->assertSame(0, DB::table('task_trash_entries')->whereNull('sequence')->count());
        $this->assertSame(2001, DB::table('task_trash_entries')->distinct()->count('sequence'));
        $this->assertSame(
            2001,
            (int) DB::table('task_trash_state')->where('id', 1)->value('last_sequence'),
        );
        $this->assertSame(
            502_000,
            (int) DB::table('task_trash_entries')->orderByDesc('sequence')->value('task_id'),
        );
        $this->assertSame(
            500_000,
            (int) DB::table('task_trash_entries')->orderBy('sequence')->value('task_id'),
        );

        $sequenceMigration->up();
        $this->assertSame(2001, DB::table('task_trash_entries')->distinct()->count('sequence'));
        $this->assertSame(
            2001,
            (int) DB::table('task_trash_state')->where('id', 1)->value('last_sequence'),
        );
    }

    public function test_rollback_is_blocked_while_deleted_tasks_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('articles', ['task_id']));
        $task = Task::query()->create(['name' => 'Rollback protected task', 'status' => 'paused']);
        $task->delete();
        $sequenceMigration = require database_path('migrations/2026_08_27_040000_add_serialized_task_trash_sequence.php');

        try {
            $sequenceMigration->down();
            $this->fail('Expected task trash rollback to be blocked.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back task trash migrations', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('task_trash_entries', 'sequence'));
        $this->assertNotNull(Task::onlyTrashed()->find($task->id));
    }

    public function test_restore_authorization_migration_backfills_protected_tasks_and_keeps_a_safe_default(): void
    {
        $restoreAuthorizationMigration = require database_path(
            'migrations/2026_08_29_092931_add_restore_authorization_to_task_trash_entries_table.php'
        );
        $restoreAuthorizationMigration->down();

        $this->assertFalse(Schema::hasColumn('task_trash_entries', 'requires_super_admin_restore'));

        $protectedTask = Task::query()->create([
            'name' => 'Protected task deleted before restore authorization migration',
            'status' => 'paused',
            'publish_scope' => 'distribution_only',
        ]);
        $regularTask = Task::query()->create([
            'name' => 'Regular task deleted before restore authorization migration',
            'status' => 'paused',
            'publish_scope' => 'local_only',
        ]);
        DB::table('task_trash_entries')->insert([
            [
                'task_id' => $protectedTask->id,
                'sequence' => 101,
                'deleted_at' => '2026-08-29 09:00:00.000001',
            ],
            [
                'task_id' => $regularTask->id,
                'sequence' => 102,
                'deleted_at' => '2026-08-29 09:00:00.000002',
            ],
        ]);

        $restoreAuthorizationMigration->up();

        $column = collect(Schema::getColumns('task_trash_entries'))
            ->first(static fn (array $column): bool => (string) ($column['name'] ?? '') === 'requires_super_admin_restore');
        $this->assertIsArray($column);
        $this->assertFalse((bool) ($column['nullable'] ?? true));
        $this->assertTrue((bool) DB::table('task_trash_entries')
            ->where('task_id', $protectedTask->id)
            ->value('requires_super_admin_restore'));
        $this->assertFalse((bool) DB::table('task_trash_entries')
            ->where('task_id', $regularTask->id)
            ->value('requires_super_admin_restore'));

        $newTask = Task::query()->create([
            'name' => 'Task deleted after restore authorization migration',
            'status' => 'paused',
            'publish_scope' => 'local_only',
        ]);
        DB::table('task_trash_entries')->insert([
            'task_id' => $newTask->id,
            'sequence' => 103,
            'deleted_at' => '2026-08-29 09:00:00.000003',
        ]);

        $this->assertFalse((bool) DB::table('task_trash_entries')
            ->where('task_id', $newTask->id)
            ->value('requires_super_admin_restore'));
    }
}
