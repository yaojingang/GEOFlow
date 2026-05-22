<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteViewLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_article_views_are_saved_for_analytics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:15:00'));

        $article = $this->publishedArticle();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
            ->withHeader('User-Agent', 'GPTBot/1.0')
            ->withHeader('Referer', 'https://example.com/ref')
            ->get('/article/'.$article->slug)
            ->assertOk();

        $this->assertDatabaseHas('view_logs', [
            'article_id' => (int) $article->id,
            'method' => 'GET',
            'path' => '/article/'.$article->slug,
            'route_name' => 'site.article',
            'status_code' => 200,
            'ip_address' => '198.51.100.23',
            'user_agent' => 'GPTBot/1.0',
            'referer' => 'https://example.com/ref',
            'created_at' => '2026-05-21 10:15:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_front_home_views_are_saved_for_path_analytics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 11:20:00'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Mozilla/5.0')
            ->get('/')
            ->assertOk();

        $this->assertDatabaseHas('view_logs', [
            'article_id' => null,
            'method' => 'GET',
            'path' => '/',
            'route_name' => 'site.home',
            'status_code' => 200,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => '2026-05-21 11:20:00',
        ]);

        Carbon::setTestNow();
    }

    private function publishedArticle(): Article
    {
        $author = Author::query()->create([
            'name' => '日志作者',
            'slug' => 'log-author',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '日志分类',
            'slug' => 'log-category',
            'status' => 'active',
        ]);

        return Article::query()->create([
            'title' => '日志测试文章',
            'slug' => 'log-test-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 0,
            'published_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);
    }
}
