<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleRiskScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoFlowCleanArticleCitationMarkersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_dry_run_by_default_and_applies_only_to_ai_articles(): void
    {
        $category = Category::query()->create(['name' => '清理命令', 'slug' => 'citation-clean-command']);
        $author = Author::query()->create(['name' => '清理命令作者']);
        $aiArticle = $this->createArticle($category, $author, 'AI 文章', 'AI 正文 [K1] Vitamin K2。', true);
        $manualArticle = $this->createArticle($category, $author, '人工文章', '人工正文 [K1]。', false);

        $this->artisan('geoflow:clean-article-citation-markers')->assertSuccessful();
        $this->assertSame('AI 正文 [K1] Vitamin K2。', $aiArticle->fresh()->content);

        $this->artisan('geoflow:clean-article-citation-markers', ['--apply' => true])->assertSuccessful();
        $this->assertSame('AI 正文 Vitamin K2。', $aiArticle->fresh()->content);
        $this->assertSame('人工正文 [K1]。', $manualArticle->fresh()->content);
        $this->assertTrue(app(ArticleRiskScanner::class)->isFresh(
            $aiArticle->fresh(),
            $aiArticle->fresh()->latestRiskScan()->first(),
        ));
    }

    private function createArticle(Category $category, Author $author, string $title, string $content, bool $aiGenerated): Article
    {
        return Article::query()->create([
            'title' => $title,
            'slug' => str($title)->slug().'-'.str()->random(6),
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => $aiGenerated,
        ]);
    }
}
