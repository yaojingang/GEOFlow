<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Task;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\GeoFlow\ManagedImageFileService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpgradeDataReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retrieval_verify_reports_deferred_tasks_and_never_writes_cache_or_database(): void
    {
        $base = KnowledgeBase::query()->create(['name' => 'Pending base', 'content' => 'Pending content', 'chunk_sync_status' => 'pending']);
        $task = Task::query()->create(['name' => 'Pending task', 'status' => 'paused', 'ai_quality_retrieval_mode' => null]);
        $task->knowledgeBases()->attach($base->id, ['sort_order' => 0]);
        Cache::flush();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $exit = Artisan::call('geoflow:backfill-ai-quality-retrieval', ['--verify' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $exit);
        $this->assertSame('fail', $report['status']);
        $this->assertSame(1, $report['counts']['tasks_deferred']);
        $this->assertArrayHasKey('atomic_fact_counts', $report['counts']);
        $this->assertNull(Cache::get('geoflow.ai-quality.rollout.v1'));
        $this->assertNull($task->fresh()->ai_quality_retrieval_mode);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|create|alter|drop)\b/i', $query);
        }
    }

    public function test_retrieval_verify_passes_only_when_every_count_is_zero(): void
    {
        $this->assertSame(0, Artisan::call('geoflow:backfill-ai-quality-retrieval', ['--verify' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['complete']);
        $this->assertCount(8, $report['counts']);
        $this->assertSame(0, array_sum($report['counts']));
    }

    public function test_image_verification_detects_missing_registry_without_reconciling_it(): void
    {
        Storage::fake('public');
        $path = 'storage/uploads/images/upgrade-readiness.png';
        Storage::disk('public')->put('uploads/images/upgrade-readiness.png', 'image');
        $library = ImageLibrary::query()->create(['name' => 'Upgrade images']);
        $image = Image::query()->create([
            'library_id' => $library->id, 'file_path' => $path, 'file_name' => 'upgrade-readiness.png',
            'filename' => 'upgrade-readiness.png', 'original_name' => 'upgrade-readiness.png',
            'managed_path_hash' => app(ManagedImageFileService::class)->pathHash($path),
            'file_size' => 5, 'mime_type' => 'image/png', 'width' => 1, 'height' => 1,
        ]);
        $this->assertSame(1, Artisan::call('geoflow:managed-images:readiness', ['--dry-run' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['registry_failed']);
        $this->assertSame(0, DB::table('managed_image_paths')->count());
        $this->assertSame(0, Artisan::call('geoflow:managed-images:readiness', ['--json' => true]));
        $before = DB::table('managed_image_paths')->first();
        $this->assertSame(0, Artisan::call('geoflow:managed-images:readiness', ['--dry-run' => true, '--json' => true]));
        $this->assertEquals($before, DB::table('managed_image_paths')->first());
        $this->assertSame($image->managed_path_hash, $before->path_hash);
        Storage::disk('public')->put('uploads/images/upgrade-readiness.png', 'tampered');
        $this->assertSame(1, Artisan::call('geoflow:managed-images:readiness', ['--dry-run' => true, '--json' => true]));
    }

    public function test_image_verification_counts_terminal_paths(): void
    {
        $library = ImageLibrary::query()->create(['name' => 'Terminal images']);
        $path = 'storage/uploads/images/../unsafe.png';
        DB::table('images')->insert([
            'library_id' => $library->id, 'file_path' => $path, 'file_name' => 'unsafe.png',
            'filename' => 'unsafe.png', 'original_name' => 'unsafe.png',
            'file_size' => 5, 'mime_type' => 'image/png', 'width' => 1, 'height' => 1,
            'managed_path_hash' => app(ManagedImageFileService::class)->terminalHashV1($path),
        ]);
        $this->assertSame(1, Artisan::call('geoflow:managed-images:readiness', ['--dry-run' => true, '--json' => true]));
        $this->assertSame(1, json_decode(Artisan::output(), true)['terminal']);
    }

    public function test_system_knowledge_verification_preserves_customization_without_queue_or_database_writes(): void
    {
        Queue::fake();
        $manager = app(SystemKnowledgeBaseManager::class);
        $this->assertSame(1, Artisan::call('geoflow:sync-system-knowledge', ['--verify' => true, '--json' => true]));
        $result = $manager->sync();
        $base = $result['knowledge_base'];
        $customized = $base->content."\n\n管理员保留内容。";
        DB::table('knowledge_bases')->where('id', $base->id)->update(['content' => $customized]);
        $manager->sync();
        $binding = DB::table('system_knowledge_bases')->first();
        Queue::fake();
        $this->assertSame(0, Artisan::call('geoflow:sync-system-knowledge', ['--verify' => true, '--json' => true]));
        $this->assertSame($customized, $base->fresh()->content);
        $this->assertEquals($binding, DB::table('system_knowledge_bases')->first());
        Queue::assertNothingPushed();
    }
}
