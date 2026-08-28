<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiQualityInspectionDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_default_quality_prompt_is_installed_once(): void
    {
        $prompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();

        $this->assertSame('quality_check', $prompt->type);
        $this->assertSame('1.0.0', $prompt->system_version);
        $this->assertStringContainsString('# R: Role', $prompt->content);
        $this->assertStringContainsString('{{fact_candidates}}', $prompt->content);
        $this->assertSame(1, Prompt::query()->where('system_key', $prompt->system_key)->count());
    }

    public function test_task_quality_policy_and_article_check_are_persisted_with_safe_defaults(): void
    {
        $prompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '质检任务',
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_model_id' => $model->id,
        ]);
        $category = Category::query()->create(['name' => '质检', 'slug' => 'quality']);
        $author = Author::query()->create(['name' => '质检员']);

        $article = new Article([
            'title' => '待检查文章',
            'slug' => 'quality-check-article',
            'content' => '正文',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => ['pass_score' => 85],
        ]);
        $article->task()->associate($task);
        $article->save();

        $check = $article->aiQualityChecks()->create([
            'request_key' => 'quality-request-1',
            'active_dedupe_key' => hash('sha256', 'article-input'),
            'status' => 'queued',
            'pass_score' => 85,
            'manual_override_min_score' => 70,
            'input_fingerprint' => hash('sha256', 'input'),
            'algorithm_version' => '1.0.0',
        ]);

        $this->assertTrue($task->fresh()->ai_quality_enabled);
        $this->assertSame(85, $task->fresh()->ai_quality_pass_score);
        $this->assertSame(70, $task->fresh()->ai_quality_manual_override_min_score);
        $this->assertTrue($article->fresh()->ai_quality_required_at_creation);
        $this->assertSame(['pass_score' => 85], $article->fresh()->ai_quality_policy_snapshot);
        $this->assertTrue($task->qualityPrompt->is($prompt));
        $this->assertTrue($task->qualityModel->is($model));
        $this->assertTrue($article->latestAiQualityCheck->is($check));
        $this->assertInstanceOf(ArticleAiQualityCheck::class, $check);
    }
}
