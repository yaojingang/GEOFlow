<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteArticleMarkdownRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_markdown_renders_gfm_tables_and_normalizes_legacy_image_urls(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml(<<<'MD'
## 二级标题

### 三级标题

| 指标 | 说明 |
| --- | --- |
| API | 已配置 |

![333.png](/uploads/images/2026/04/demo.png)

- [x] 已完成
MD);

        $this->assertStringContainsString('<h2>二级标题</h2>', $html);
        $this->assertStringContainsString('<h3>三级标题</h3>', $html);
        $this->assertStringContainsString('<div class="article-table-wrap"><table class="article-table">', $html);
        $this->assertStringContainsString('src="/storage/uploads/images/2026/04/demo.png"', $html);
        $this->assertStringNotContainsString('333.png', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_published_article_page_outputs_normalized_image_url(): void
    {
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => 'Markdown 渲染测试',
            'slug' => 'markdown-render-test',
            'excerpt' => '',
            'content' => "## 小节\n\n![333.png](uploads/images/2026/04/demo.png)\n\n| A | B |\n| --- | --- |\n| 1 | 2 |",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'published_at' => now(),
        ]);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('src="/storage/uploads/images/2026/04/demo.png"', false)
            ->assertSee('<table class="article-table">', false)
            ->assertDontSee('333.png', false);
    }
}
