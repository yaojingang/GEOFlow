<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\Task;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Services\Topics\TopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TopicMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('migrationAndSavedData')]
    public function test_each_topic_migration_refuses_saved_data_before_changing_schema(string $migration, string $fixture): void
    {
        $actor = Admin::query()->create(['username' => 'rollback-protection', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $this->createSavedData($fixture, $actor);
        $columns = $this->domainColumns();
        $rows = $this->domainRowCounts();
        try {
            (require database_path('migrations/'.$migration))->down();
            $this->fail('Expected saved domain data to prevent rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('专题数据已存在', $exception->getMessage());
        }
        $this->assertSame($columns, $this->domainColumns());
        $this->assertSame($rows, $this->domainRowCounts());
    }

    public static function migrationAndSavedData(): array
    {
        $cases = [];
        foreach ([
            '2026_10_01_064730_create_topics_tables.php',
            '2026_10_01_084859_add_topic_freshness_snapshots_and_runtime_state.php',
            '2026_10_01_094730_add_topic_display_order.php',
            '2026_10_01_094740_create_topic_paths.php',
            '2026_10_02_000100_add_topic_execution_support.php',
            '2026_10_02_000110_add_topic_dispatch_key.php',
            '2026_10_02_000120_add_topic_review_records.php',
        ] as $migration) {
            foreach (['draft', 'paused_task', 'pending_build', 'batch'] as $fixture) {
                $cases[$migration.' '.$fixture] = [$migration, $fixture];
            }
        }

        return $cases;
    }

    public function test_multi_step_rollback_preserves_all_columns_and_pending_dispatch_keys(): void
    {
        $actor = Admin::query()->create(['username' => 'rollback-dispatch', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $topic = app(TopicService::class)->create('primary', ['title' => 'Rollback draft'], $actor->id);
        $dispatch = (string) Str::uuid();
        $run = TopicBuildRun::query()->create([
            'request_key' => (string) Str::uuid(), 'dispatch_key' => $dispatch, 'site_key' => 'primary', 'topic_id' => $topic->id,
            'owner_admin_id' => $actor->id, 'identity' => [], 'input' => [], 'status' => 'pending',
        ]);
        $columns = $this->domainColumns();
        $migrations = DB::table('migrations')->orderBy('id')->get()->toArray();
        try {
            Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);
            $this->fail('Expected rollback to retain existing topic data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('专题数据已存在', $exception->getMessage());
        }
        $this->assertSame($columns, $this->domainColumns());
        $this->assertSame($dispatch, $run->fresh()->dispatch_key);
        $this->assertEquals($migrations, DB::table('migrations')->orderBy('id')->get()->toArray());
    }

    public function test_empty_topic_domain_can_roll_back_all_topic_migrations_and_reapply_them(): void
    {
        $task = Task::query()->create(['name' => 'Article task survives', 'content_type' => 'article', 'status' => 'paused']);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 7, '--force' => true]));
        $this->assertFalse(Schema::hasTable('topics'));
        $this->assertFalse(Schema::hasTable('topic_build_runs'));
        $this->assertFalse(Schema::hasColumn('tasks', 'content_type'));
        $this->assertSame('Article task survives', DB::table('tasks')->where('id', $task->id)->value('name'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasColumn('topics', 'review_records'));
        $this->assertTrue(Schema::hasColumn('topic_build_runs', 'dispatch_key'));
        $this->assertSame('article', $task->fresh()->content_type);
    }

    private function createSavedData(string $fixture, Admin $actor): void
    {
        match ($fixture) {
            'draft' => app(TopicService::class)->create('primary', ['title' => 'Protected draft'], $actor->id),
            'paused_task' => Task::query()->create(['name' => 'Paused topic task', 'content_type' => 'topic', 'target_site_key' => 'primary', 'topic_settings' => ['template_key' => 'guide'], 'status' => 'paused']),
            'pending_build' => TopicBuildRun::query()->create(['request_key' => (string) Str::uuid(), 'dispatch_key' => (string) Str::uuid(), 'site_key' => 'primary', 'owner_admin_id' => $actor->id, 'identity' => [], 'input' => [], 'status' => 'pending']),
            'batch' => TopicImportBatch::query()->create(['request_key' => (string) Str::uuid(), 'site_key' => 'primary', 'owner_admin_id' => $actor->id, 'settings' => ['mode' => 'draft'], 'rows' => [['title' => 'Pending title']], 'status' => 'pending']),
        };
    }

    private function domainColumns(): array
    {
        return collect(['topics', 'topic_revisions', 'topic_revision_articles', 'topic_source_invalidations', 'topic_freshness_changes', 'topic_paths', 'topic_build_runs', 'topic_import_batches', 'tasks', 'task_runs'])
            ->mapWithKeys(fn (string $table): array => [$table => Schema::getColumnListing($table)])->all();
    }

    private function domainRowCounts(): array
    {
        return collect(array_keys($this->domainColumns()))->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
