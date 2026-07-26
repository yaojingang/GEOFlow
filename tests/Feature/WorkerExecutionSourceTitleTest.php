<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Category;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);
        $article = $title->articles()->whereKey((int) $result['article_id'])->firstOrFail();

        $this->assertSame((int) $title->id, (int) $article->source_title_id);
        $this->assertSame(1, (int) $title->fresh()->used_count);
        $this->assertSame(1, (int) $title->fresh()->usage_count);
    }
}
