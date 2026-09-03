<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkerExecutionSourceTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_article_keeps_the_selected_source_title_relation(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'test-chat-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# 自动文章\n\n完整正文。"],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);
        Category::query()->create([
            'name' => '默认分类',
            'slug' => 'default-category',
            'sort_order' => 1,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Worker Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create(['name' => '自动标题库']);
        $title = Title::query()->create([
            'library_id' => $library->id,
            'title' => '自动文章',
            'keyword' => 'GEO',
        ]);
        $task = Task::query()->create([
            'name' => '自动文章任务',
            'title_library_id' => $library->id,
            'ai_model_id' => $model->id,
            'draft_limit' => 10,
            'article_limit' => 10,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '自动文章知识库',
            'content' => 'GEO 文章需要可信知识依据。',
            'review_status' => 'reviewed',
        ]);
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'GEO 文章需要可信知识依据。',
            'content_hash' => hash('sha256', 'GEO 文章需要可信知识依据。'),
            'source_hash' => 'generation-source-v1',
            'metadata_json' => '{}',
            'embedding_json' => '[]',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);

        $result = app(WorkerExecutionService::class)->executeTask(
            (int) $task->id,
            $this->executionContext($task, $model, 'source-title-worker'),
        );
        $article = $title->articles()->whereKey((int) $result['article_id'])->firstOrFail();

        $this->assertSame((int) $title->id, (int) $article->source_title_id);
        $this->assertSame(1, (int) $title->fresh()->used_count);
        $this->assertSame(1, (int) $title->fresh()->usage_count);
        $this->assertSame((int) $chunk->id, $article->generation_evidence_snapshot[0]['chunk_id']);
        $this->assertSame('generation-source-v1', $article->generation_evidence_snapshot[0]['source_hash']);
    }

    public function test_generation_uses_the_locked_fresh_task_quality_policy(): void
    {
        Queue::fake();
        Category::query()->create([
            'name' => 'Fresh policy category',
            'slug' => 'fresh-policy-category',
            'sort_order' => 1,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Fresh Policy Worker Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'fresh-policy-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://fresh-policy.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $qualityPrompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $library = TitleLibrary::query()->create(['name' => 'Fresh policy titles']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'Fresh policy article',
            'keyword' => 'quality',
        ]);
        $task = Task::query()->create([
            'name' => 'Fresh policy task',
            'title_library_id' => $library->id,
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => false,
            'draft_limit' => 10,
            'article_limit' => 10,
            'status' => 'active',
            'schedule_enabled' => 1,
            'need_review' => false,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Fresh policy knowledge',
            'content' => 'Quality knowledge.',
            'review_status' => 'reviewed',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        Http::fake(function () use ($task, $qualityPrompt) {
            $task->newQuery()->whereKey($task->id)->update([
                'ai_quality_enabled' => true,
                'ai_quality_prompt_id' => $qualityPrompt->id,
                'ai_quality_pass_score' => 85,
                'ai_quality_manual_override_min_score' => 70,
            ]);

            return Http::response([
                'model' => 'fresh-policy-chat-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Fresh policy article\n\nComplete content."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ]);
        });

        $result = app(WorkerExecutionService::class)->executeTask(
            (int) $task->id,
            $this->executionContext($task, $model, 'fresh-policy-worker'),
        );
        $article = Article::query()->findOrFail((int) $result['article_id']);

        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertTrue((bool) data_get($article->ai_quality_policy_snapshot, 'required'));
        $this->assertSame('pending', $article->review_status);
        $this->assertSame(1, ArticleAiQualityCheck::query()->where('article_id', $article->id)->count());
        $this->assertTrue((bool) data_get($result, 'meta.ai_quality.required'));
    }

    private function executionContext(Task $task, AiModel $model, string $username): AiExecutionContext
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => (int) $admin->ai_config_access_version,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => (string) Str::uuid(),
        ])->save();

        return app(AiExecutionContextFactory::class)->fromTaskRun($run);
    }
}
