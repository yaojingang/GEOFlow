<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_does_not_seed_frontend_reference_content_by_default(): void
    {
        Config::set('geoflow.seed_frontend_demo', false);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Article::query()->count());
    }

    public function test_database_seeder_can_seed_frontend_reference_content_when_enabled(): void
    {
        Config::set('geoflow.seed_frontend_demo', true);
        Config::set('geoflow.seed_frontend_demo_overwrite', false);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertSame(2, Category::query()->count());
        $this->assertSame(50, Article::query()->count());
        $this->assertSame(1, Category::query()->where('slug', 'geoflow-getting-started')->count());
    }

    public function test_frontend_reference_seed_does_not_overwrite_existing_user_owned_rows(): void
    {
        Config::set('geoflow.seed_frontend_demo', true);
        Config::set('geoflow.seed_frontend_demo_overwrite', false);

        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => '用户自己的站点名称',
        ]);

        $category = Category::query()->create([
            'slug' => 'geoflow-getting-started',
            'name' => '用户自己的功能分类',
            'description' => '用户写的分类描述',
            'sort_order' => 77,
        ]);

        $author = Author::query()->create([
            'name' => '用户作者',
            'email' => 'editor@geoflow.local',
            'bio' => '用户写的作者说明',
        ]);

        Article::query()->create([
            'slug' => 'gnflg8xg',
            'title' => '用户自己的文章标题',
            'excerpt' => '用户自己的摘要',
            'content' => '用户自己的正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('用户自己的站点名称', SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));

        $category->refresh();
        $this->assertSame('用户自己的功能分类', $category->name);
        $this->assertSame('用户写的分类描述', $category->description);
        $this->assertSame(77, $category->sort_order);

        $author->refresh();
        $this->assertSame('用户作者', $author->name);
        $this->assertSame('用户写的作者说明', $author->bio);

        $article = Article::query()->where('slug', 'gnflg8xg')->firstOrFail();
        $this->assertSame('用户自己的文章标题', $article->title);
        $this->assertSame('用户自己的正文', $article->content);
    }

    public function test_frontend_reference_seed_only_overwrites_owned_rows_when_explicitly_enabled(): void
    {
        Config::set('geoflow.seed_frontend_demo', true);
        Config::set('geoflow.seed_frontend_demo_overwrite', true);

        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => '用户自己的站点名称',
        ]);

        $category = Category::query()->create([
            'slug' => 'geoflow-getting-started',
            'name' => '用户自己的功能分类',
            'description' => '用户写的分类描述',
            'sort_order' => 77,
        ]);

        $author = Author::query()->create([
            'name' => '用户作者',
            'email' => 'editor@geoflow.local',
            'bio' => '用户写的作者说明',
        ]);

        Article::query()->create([
            'slug' => 'gnflg8xg',
            'title' => '用户自己的文章标题',
            'excerpt' => '用户自己的摘要',
            'content' => '用户自己的正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('用户自己的站点名称', SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));

        $category->refresh();
        $this->assertSame('功能指南', $category->name);
        $this->assertSame(10, $category->sort_order);

        $author->refresh();
        $this->assertSame('GEOFlow 编辑部', $author->name);

        $article = Article::query()->where('slug', 'gnflg8xg')->firstOrFail();
        $this->assertSame('GEOFlow 2.3.0 是什么？面向 AI 搜索的开源内容工程系统详解', $article->title);
        $this->assertStringContainsString('系统定位：内容工程', $article->content);
    }
}
