<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
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

    public function test_theme_article_page_renders_array_based_sticky_ad(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'tdwh-netease-news-en-20260508']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_ads'],
            ['setting_value' => json_encode([
                [
                    'id' => 'test_ad',
                    'badge' => 'Demo',
                    'title' => 'Demo CTA',
                    'copy' => 'Array based sticky ad copy',
                    'button_text' => 'Read more',
                    'button_url' => '/category/tech',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => 'Sticky Ad 渲染测试',
            'slug' => 'sticky-ad-render-test',
            'excerpt' => '',
            'content' => '## 正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'published_at' => now(),
        ]);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('Demo CTA')
            ->assertSee('Array based sticky ad copy')
            ->assertSee('Read more');
    }

    public function test_article_page_renders_content_text_ads_around_article_body(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_text_ads'],
            ['setting_value' => json_encode([
                [
                    'id' => 'top-text-ad',
                    'name' => 'Top Text Ad',
                    'placement' => 'content_top',
                    'text' => 'Top <Deal>',
                    'url' => '/promo?#intro',
                    'text_color' => '#ff6600',
                    'open_new_tab' => false,
                    'tracking_enabled' => true,
                    'tracking_param' => 'utm_source=geoflow',
                    'enabled' => true,
                    'sort_order' => 10,
                ],
                [
                    'id' => 'bottom-text-ad',
                    'name' => 'Bottom Text Ad',
                    'placement' => 'content_bottom',
                    'text' => 'Bottom CTA',
                    'url' => 'https://example.com/bottom',
                    'text_color' => '#2563eb',
                    'open_new_tab' => true,
                    'tracking_enabled' => false,
                    'tracking_param' => '',
                    'enabled' => true,
                    'sort_order' => 20,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => '正文广告渲染测试',
            'slug' => 'article-text-ad-render-test',
            'excerpt' => '',
            'content' => '## 正文标题',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'published_at' => now(),
        ]);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('article-text-ads--content-top', false)
            ->assertSee('article-text-ads--content-bottom', false)
            ->assertSee('Top &lt;Deal&gt;', false)
            ->assertDontSee('Top <Deal>', false)
            ->assertSee('href="/promo?utm_source=geoflow#intro"', false)
            ->assertDontSee('href="/promo?&utm_source=geoflow#intro"', false)
            ->assertSee('href="https://example.com/bottom"', false)
            ->assertSee('rel="noopener sponsored nofollow"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('--article-text-ad-color: #ff6600;', false);
    }

    public function test_article_page_renders_module_based_content_text_ads(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_text_ads'],
            ['setting_value' => json_encode([
                [
                    'id' => 'module-top',
                    'name' => 'Top Module',
                    'placement' => 'content_top',
                    'enabled' => true,
                    'sort_order' => 10,
                    'links' => [
                        [
                            'id' => 'module-top-link-1',
                            'text' => 'Top Module CTA',
                            'url' => '/module-top',
                            'text_color' => '#2563eb',
                            'open_new_tab' => false,
                            'tracking_enabled' => true,
                            'tracking_param' => 'utm_campaign=module',
                            'enabled' => true,
                            'sort_order' => 10,
                        ],
                        [
                            'id' => 'module-top-link-2',
                            'text' => 'Disabled Module CTA',
                            'url' => '/disabled',
                            'enabled' => false,
                            'sort_order' => 20,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'module-tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => '模块广告渲染测试',
            'slug' => 'article-text-ad-module-render-test',
            'excerpt' => '',
            'content' => '## 正文标题',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'published_at' => now(),
        ]);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('article-text-ad-module', false)
            ->assertSee('data-module-id="module-top"', false)
            ->assertSee('Top Module CTA')
            ->assertSee('href="/module-top?utm_campaign=module"', false)
            ->assertDontSee('Disabled Module CTA');
    }

    public function test_homepage_uses_explicit_hot_and_featured_articles(): void
    {
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        Article::query()->create([
            'title' => '首页热门文章',
            'slug' => 'homepage-hot-article',
            'excerpt' => '热门摘要',
            'content' => '热门正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_hot' => true,
            'published_at' => now(),
        ]);
        Article::query()->create([
            'title' => '首页精选文章',
            'slug' => 'homepage-featured-article',
            'excerpt' => '精选摘要',
            'content' => '精选正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('热点')
            ->assertSee('首页热门文章')
            ->assertSee('精选文章')
            ->assertSee('首页精选文章');
    }

    public function test_frontend_category_navigation_hides_categories_without_published_articles(): void
    {
        $visibleCategory = Category::query()->create([
            'name' => '可见分类',
            'slug' => 'visible-category',
        ]);
        Category::query()->create([
            'name' => '空分类',
            'slug' => 'empty-category',
        ]);
        $draftCategory = Category::query()->create([
            'name' => '草稿分类',
            'slug' => 'draft-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        Article::query()->create([
            'title' => '已发布文章',
            'slug' => 'published-category-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $visibleCategory->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        Article::query()->create([
            'title' => '草稿文章',
            'slug' => 'draft-category-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $draftCategory->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('可见分类')
            ->assertDontSee('空分类')
            ->assertDontSee('草稿分类');
    }

    public function test_frontend_theme_loads_external_assets_without_inline_css(): void
    {
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('js/tailwindcss.play-cdn.js', false)
            ->assertSee('js/lucide.min.js', false)
            ->assertSee('themes/toutiao-news-20260426/theme.css', false)
            ->assertSee('themes/toutiao-news-20260426/theme.js', false)
            ->assertSee('application/ld+json', false)
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertDontSee('unpkg.com/lucide', false)
            ->assertDontSee('<style>', false)
            ->assertDontSee('data-hot-carousel]).forEach', false);
    }

    public function test_homepage_renders_configured_carousel_and_sidebar_feed_panel(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'GEOFlow Demo']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_description'],
            ['setting_value' => 'Demo homepage description']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'home_carousel_slides'],
            ['setting_value' => json_encode([
                [
                    'image_url' => 'https://example.com/banner-one.jpg',
                    'title' => 'Banner One',
                    'link_url' => '/article/demo',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('data-home-poster-carousel', false)
            ->assertSee('https://example.com/banner-one.jpg', false)
            ->assertSee('Banner One')
            ->assertSee('GEOFlow Feed')
            ->assertSee('GEOFlow Demo')
            ->assertSee('Demo homepage description');
    }
}
