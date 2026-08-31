<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiQualityRetrievalBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_repeatable_and_establishes_a_serving_generation(): void
    {
        $sourceHash = hash('sha256', '历史正文');
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '历史知识库',
            'content' => '历史正文',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $sourceHash,
        ]);
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '历史正文',
            'content_hash' => $sourceHash,
            'source_hash' => $sourceHash,
        ]);
        $task = Task::query()->create([
            'name' => '历史任务',
            'status' => 'paused',
            'ai_quality_retrieval_mode' => null,
        ]);
        $task->knowledgeBases()->attach($knowledgeBase->id, ['sort_order' => 0]);
        DB::table('knowledge_bases')->where('id', $knowledgeBase->id)->update([
            'ai_quality_content_hash' => '',
            'ai_quality_content_length' => 0,
        ]);

        $this->artisan('geoflow:backfill-ai-quality-retrieval', ['--batch' => 1])
            ->expectsOutput('Backfilled tasks: 1')
            ->expectsOutput('Backfilled knowledge_bases: 1')
            ->expectsOutput('Backfilled readiness_projections: 1')
            ->assertSuccessful();

        $task->refresh();
        $knowledgeBase->refresh();
        $chunk->refresh();
        $this->assertSame('chunk', $task->ai_quality_retrieval_mode);
        $this->assertNotNull($knowledgeBase->chunk_serving_generation);
        $this->assertSame($knowledgeBase->chunk_serving_generation, $chunk->generation_key);
        $this->assertSame($sourceHash, $knowledgeBase->chunk_serving_source_hash);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $knowledgeBase->chunk_manifest_hash);
        $this->assertSame($sourceHash, $knowledgeBase->ai_quality_content_hash);
        $this->assertSame(4, $knowledgeBase->ai_quality_content_length);

        $this->artisan('geoflow:backfill-ai-quality-retrieval', ['--batch' => 1])
            ->expectsOutput('Backfilled tasks: 0')
            ->expectsOutput('Backfilled knowledge_bases: 0')
            ->expectsOutput('Backfilled readiness_projections: 0')
            ->assertSuccessful();
    }

    public function test_backfill_defers_tasks_until_chunk_retrieval_is_ready(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '尚未切片知识库',
            'content' => '等待切片的历史正文',
            'chunk_sync_status' => 'pending',
        ]);
        $task = Task::query()->create([
            'name' => '待就绪历史任务',
            'status' => 'paused',
            'ai_quality_retrieval_mode' => null,
        ]);
        $task->knowledgeBases()->attach($knowledgeBase->id, ['sort_order' => 0]);

        $this->artisan('geoflow:backfill-ai-quality-retrieval')
            ->expectsOutput('Backfilled tasks: 0')
            ->expectsOutput('Backfilled tasks_deferred: 1')
            ->assertSuccessful();

        $this->assertNull($task->fresh()->ai_quality_retrieval_mode);
    }

    public function test_backfill_stales_legacy_gate_results_without_a_retrieval_basis(): void
    {
        $task = Task::query()->create([
            'name' => '历史质检任务',
            'status' => 'paused',
        ]);
        $category = Category::query()->create(['name' => '历史质检分类', 'slug' => 'legacy-quality-category']);
        $author = Author::query()->create(['name' => '历史质检作者']);
        $article = Article::query()->create([
            'task_id' => $task->id,
            'title' => '历史文章',
            'slug' => 'legacy-quality-article',
            'content' => '历史文章正文',
            'status' => 'draft',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);
        $check = ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'task_id' => $task->id,
            'request_key' => (string) Str::uuid(),
            'input_fingerprint' => str_repeat('f', 64),
            'algorithm_version' => 'legacy-quality-test',
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'gate_applied' => true,
            'requested_retrieval_mode' => null,
            'retrieval_basis_hash' => null,
            'finished_at' => now(),
        ]);

        $this->artisan('geoflow:backfill-ai-quality-retrieval')
            ->expectsOutput('Backfilled checks: 1')
            ->expectsOutput('Backfilled checks_staled: 1')
            ->assertSuccessful();

        $check->refresh();
        $this->assertSame('stale', $check->status);
        $this->assertSame('retrieval_basis_missing', $check->error_code);
        $this->assertNull($check->active_dedupe_key);
    }
}
