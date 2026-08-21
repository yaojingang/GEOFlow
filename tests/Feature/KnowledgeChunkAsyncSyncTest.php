<?php

namespace Tests\Feature;

use App\Jobs\PrepareKnowledgeChunkSyncJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeChunkAsyncSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_sync_keeps_active_chunks_until_the_queue_finishes(): void
    {
        Queue::fake();

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '异步知识库',
            'description' => '',
            'content' => '更新后的知识内容。',
            'character_count' => 9,
            'file_type' => 'markdown',
            'word_count' => 9,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '仍在提供服务的旧切片。',
            'content_hash' => hash('sha256', '仍在提供服务的旧切片。'),
            'token_count' => 8,
            'embedding_json' => '[]',
        ]);

        $queued = app(KnowledgeChunkSyncCoordinator::class)->request(
            (int) $knowledgeBase->id,
            requireRealEmbedding: true,
        );

        $this->assertTrue($queued);
        $knowledgeBase->refresh();
        $this->assertSame('pending', $knowledgeBase->chunk_sync_status);
        $this->assertNotSame('', (string) $knowledgeBase->chunk_sync_token);
        $this->assertSame(
            '仍在提供服务的旧切片。',
            (string) KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBase->id)->value('content')
        );

        Queue::assertPushed(
            PrepareKnowledgeChunkSyncJob::class,
            fn (PrepareKnowledgeChunkSyncJob $job): bool => $job->knowledgeBaseId === (int) $knowledgeBase->id
                && $job->syncToken === (string) $knowledgeBase->chunk_sync_token
                && $job->requireRealEmbedding
                && $job->queue === 'knowledge'
                && $job->afterCommit === true
        );
    }

    public function test_sync_dispatch_is_deferred_until_the_surrounding_transaction_commits(): void
    {
        Queue::fake();

        DB::transaction(function (): void {
            $knowledgeBase = KnowledgeBase::query()->create([
                'name' => '事务内知识库',
                'description' => '',
                'content' => '提交后再处理的正文。',
                'character_count' => 10,
                'file_type' => 'markdown',
                'word_count' => 10,
            ]);

            $this->assertTrue(
                app(KnowledgeChunkSyncCoordinator::class)->request((int) $knowledgeBase->id)
            );
        });

        Queue::assertPushed(
            PrepareKnowledgeChunkSyncJob::class,
            fn (PrepareKnowledgeChunkSyncJob $job): bool => $job->afterCommit === true
                && $job->queue === 'knowledge'
        );
    }

    public function test_an_identical_pending_sync_is_deduplicated(): void
    {
        Queue::fake();

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '去重知识库',
            'description' => '',
            'content' => '同一份正文。',
            'character_count' => 6,
            'file_type' => 'markdown',
            'word_count' => 6,
        ]);

        $coordinator = app(KnowledgeChunkSyncCoordinator::class);

        $this->assertTrue($coordinator->request((int) $knowledgeBase->id));
        $this->assertFalse($coordinator->request((int) $knowledgeBase->id));

        Queue::assertPushed(PrepareKnowledgeChunkSyncJob::class, 1);
    }

    public function test_a_forced_refresh_always_queues_the_latest_sync_token(): void
    {
        Queue::fake();

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '连续刷新知识库',
            'description' => '',
            'content' => '连续刷新正文。',
            'character_count' => 7,
            'file_type' => 'markdown',
            'word_count' => 7,
        ]);

        $coordinator = app(KnowledgeChunkSyncCoordinator::class);
        $this->assertTrue($coordinator->request((int) $knowledgeBase->id));
        $firstToken = (string) $knowledgeBase->fresh()->chunk_sync_token;
        $this->assertTrue($coordinator->request((int) $knowledgeBase->id, force: true));
        $latestToken = (string) $knowledgeBase->fresh()->chunk_sync_token;

        $this->assertNotSame($firstToken, $latestToken);
        Queue::assertPushed(PrepareKnowledgeChunkSyncJob::class, 2);
        Queue::assertPushed(
            PrepareKnowledgeChunkSyncJob::class,
            fn (PrepareKnowledgeChunkSyncJob $job): bool => $job->syncToken === $latestToken
                && $job->uniqueId() === $knowledgeBase->id.':'.$latestToken
        );
    }

    public function test_finalization_replaces_old_chunks_only_after_staging_is_ready(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '切换知识库',
            'description' => '',
            'content' => '新版切片正文。',
            'character_count' => 7,
            'file_type' => 'markdown',
            'word_count' => 7,
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'sync-version-1',
            'chunk_source_hash' => hash('sha256', '新版切片正文。'),
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '旧版仍然可检索。',
            'content_hash' => hash('sha256', '旧版仍然可检索。'),
            'token_count' => 7,
            'embedding_json' => '[]',
        ]);

        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync(
            (int) $knowledgeBase->id,
            (string) $knowledgeBase->content,
            'sync-version-1',
        );

        $this->assertSame('旧版仍然可检索。', (string) $knowledgeBase->chunks()->value('content'));

        $service->finalizeStagingSync((int) $knowledgeBase->id, 'sync-version-1');

        $this->assertSame('新版切片正文。', (string) $knowledgeBase->chunks()->value('content'));
        $this->assertSame('ready', (string) $knowledgeBase->fresh()->chunk_sync_status);
        $this->assertDatabaseCount('knowledge_chunk_sync_rows', 0);
    }

    public function test_successful_finalization_is_safe_to_redeliver(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '重复提交知识库',
            'description' => '',
            'content' => '已经提交的新切片。',
            'character_count' => 9,
            'file_type' => 'markdown',
            'word_count' => 9,
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'commit-token',
        ]);

        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync(
            (int) $knowledgeBase->id,
            (string) $knowledgeBase->content,
            'commit-token',
        );

        $this->assertTrue($service->finalizeStagingSync((int) $knowledgeBase->id, 'commit-token'));
        $this->assertFalse($service->finalizeStagingSync((int) $knowledgeBase->id, 'commit-token'));

        app(KnowledgeChunkSyncCoordinator::class)->markFailed(
            (int) $knowledgeBase->id,
            'commit-token',
            'late acknowledgement failure',
        );

        $knowledgeBase->refresh();
        $this->assertSame('ready', $knowledgeBase->chunk_sync_status);
        $this->assertNull($knowledgeBase->chunk_sync_token);
        $this->assertNull($knowledgeBase->chunk_sync_error);
    }

    public function test_failed_pipeline_is_not_considered_current_on_redelivery(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '失败任务知识库',
            'description' => '',
            'content' => '失败后等待人工重试。',
            'character_count' => 10,
            'file_type' => 'markdown',
            'word_count' => 10,
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'failed-token',
        ]);
        $coordinator = app(KnowledgeChunkSyncCoordinator::class);

        $coordinator->markFailed(
            (int) $knowledgeBase->id,
            'failed-token',
            'provider unavailable',
        );

        $this->assertFalse($coordinator->isCurrent((int) $knowledgeBase->id, 'failed-token'));
        $this->assertSame('failed', $knowledgeBase->fresh()->chunk_sync_status);
    }

    public function test_stale_pipeline_cannot_replace_the_current_chunks(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '过期任务知识库',
            'description' => '',
            'content' => '当前正文。',
            'character_count' => 5,
            'file_type' => 'markdown',
            'word_count' => 5,
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'current-token',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '当前有效切片。',
            'content_hash' => hash('sha256', '当前有效切片。'),
            'token_count' => 6,
            'embedding_json' => '[]',
        ]);

        $this->assertFalse(
            app(KnowledgeChunkSyncService::class)
                ->finalizeStagingSync((int) $knowledgeBase->id, 'stale-token')
        );
        $this->assertSame('当前有效切片。', (string) $knowledgeBase->chunks()->value('content'));
    }

    public function test_stale_pipeline_is_requeued_with_its_embedding_requirement(): void
    {
        Queue::fake();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '停滞知识库',
            'description' => '',
            'content' => '需要恢复的正文。',
            'character_count' => 8,
            'file_type' => 'markdown',
            'word_count' => 8,
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'stale-sync-token',
            'chunk_source_hash' => hash('sha256', '需要恢复的正文。'),
            'chunk_sync_require_real_embedding' => true,
        ]);
        DB::table('knowledge_bases')
            ->where('id', $knowledgeBase->id)
            ->update(['updated_at' => now()->subMinutes(20)]);
        DB::table('knowledge_chunk_sync_rows')->insert([
            'knowledge_base_id' => $knowledgeBase->id,
            'sync_token' => 'stale-sync-token',
            'chunk_index' => 0,
            'content' => '旧暂存切片',
            'content_hash' => hash('sha256', '旧暂存切片'),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        $this->assertSame(
            1,
            app(KnowledgeChunkSyncCoordinator::class)->recoverStale(600)
        );

        $knowledgeBase->refresh();
        $this->assertSame('pending', $knowledgeBase->chunk_sync_status);
        $this->assertNotSame('stale-sync-token', $knowledgeBase->chunk_sync_token);
        $this->assertDatabaseMissing('knowledge_chunk_sync_rows', [
            'knowledge_base_id' => $knowledgeBase->id,
            'sync_token' => 'stale-sync-token',
        ]);
        Queue::assertPushed(
            PrepareKnowledgeChunkSyncJob::class,
            fn (PrepareKnowledgeChunkSyncJob $job): bool => $job->syncToken === $knowledgeBase->chunk_sync_token
                && $job->requireRealEmbedding
                && $job->afterCommit === true
        );
    }

    public function test_recovery_does_not_supersede_a_pipeline_that_started_making_progress(): void
    {
        Queue::fake();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '活跃知识库',
            'description' => '',
            'content' => '仍在处理的正文。',
            'character_count' => 8,
            'file_type' => 'markdown',
            'word_count' => 8,
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'active-sync-token',
            'updated_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            0,
            app(KnowledgeChunkSyncCoordinator::class)->recoverStale(600)
        );
        $this->assertSame('active-sync-token', $knowledgeBase->fresh()->chunk_sync_token);
        Queue::assertNothingPushed();
    }

    public function test_dense_short_lines_use_the_bounded_chunking_path(): void
    {
        $content = str_repeat("a\n", 60000);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '密集换行知识库',
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content),
            'file_type' => 'text',
            'word_count' => mb_strlen($content),
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'dense-lines-token',
        ]);

        $chunkCount = app(KnowledgeChunkSyncService::class)->prepareStagingSync(
            (int) $knowledgeBase->id,
            $content,
            'dense-lines-token',
        );

        $this->assertGreaterThan(1, $chunkCount);
        $this->assertSame(
            $chunkCount,
            (int) DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->count()
        );
    }

    public function test_many_short_headings_cannot_amplify_into_unbounded_chunks(): void
    {
        $content = implode("\n", array_map(
            static fn (int $index): string => '# 标题 '.$index,
            range(1, 50000),
        ));
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '连续标题知识库',
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content),
            'file_type' => 'markdown',
            'word_count' => mb_strlen($content),
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'many-headings-token',
        ]);

        $chunkCount = app(KnowledgeChunkSyncService::class)->prepareStagingSync(
            (int) $knowledgeBase->id,
            $content,
            'many-headings-token',
        );

        $expectedCharacterChunks = (int) ceil(mb_strlen($content, 'UTF-8') / 900);
        $this->assertLessThanOrEqual($expectedCharacterChunks + 1, $chunkCount);
        $this->assertLessThan(1000, $chunkCount);
    }

    public function test_near_limit_content_stays_within_the_knowledge_worker_memory_budget(): void
    {
        $baselineMemory = memory_get_usage(true);
        memory_reset_peak_usage();
        $content = str_repeat(str_repeat('a', 145)."\n", 50000);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '接近上限的知识库',
            'description' => '',
            'content' => $content,
            'character_count' => strlen($content),
            'file_type' => 'text',
            'word_count' => strlen($content),
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'near-limit-token',
        ]);

        $chunkCount = app(KnowledgeChunkSyncService::class)->prepareStagingSync(
            (int) $knowledgeBase->id,
            $content,
            'near-limit-token',
        );

        $this->assertGreaterThan(1, $chunkCount);
        $this->assertSame(
            $chunkCount,
            (int) DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->count()
        );
        $this->assertLessThanOrEqual(
            128 * 1024 * 1024,
            memory_get_peak_usage(true) - $baselineMemory,
        );
    }

    public function test_first_embedding_batch_keeps_existing_fallback_rows_when_no_model_is_configured(): void
    {
        $content = str_repeat('无模型知识内容。', 500);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '无向量模型知识库',
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content),
            'file_type' => 'text',
            'word_count' => mb_strlen($content),
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'no-model-token',
        ]);

        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync(
            (int) $knowledgeBase->id,
            $content,
            'no-model-token',
        );
        DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->update(['updated_at' => '2020-01-01 00:00:00']);

        $result = $service->embedStagingBatch(
            (int) $knowledgeBase->id,
            'no-model-token',
            0,
        );

        $this->assertTrue((bool) ($result['done'] ?? false));
        $this->assertSame(
            '2020-01-01 00:00:00',
            (string) DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->orderBy('id')
                ->value('updated_at')
        );
    }

    public function test_a_later_embedding_fallback_resets_the_whole_version_consistently(): void
    {
        $content = implode(
            "\n\n",
            array_fill(0, 80, str_repeat('知识内容', 250))
        );
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '向量一致性知识库',
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content),
            'file_type' => 'text',
            'word_count' => mb_strlen($content),
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'fallback-token',
        ]);

        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync(
            (int) $knowledgeBase->id,
            $content,
            'fallback-token',
        );

        $firstBatchIds = DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->orderBy('id')
            ->limit(32)
            ->pluck('id');
        $this->assertCount(32, $firstBatchIds);
        DB::table('knowledge_chunk_sync_rows')
            ->whereIn('id', $firstBatchIds)
            ->update([
                'embedding_model_id' => 99,
                'embedding_dimensions' => 3,
                'embedding_provider' => 'example.test',
                'embedding_json' => '[0.1,0.2,0.3]',
            ]);

        $result = $service->embedStagingBatch(
            (int) $knowledgeBase->id,
            'fallback-token',
            (int) $firstBatchIds->last(),
        );

        $this->assertTrue((bool) ($result['done'] ?? false));
        $this->assertSame(
            0,
            DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->whereNotNull('embedding_model_id')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->where('embedding_dimensions', '>', 0)
                ->count()
        );
    }
}
