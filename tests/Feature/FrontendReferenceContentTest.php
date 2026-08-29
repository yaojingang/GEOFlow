<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Database\Seeders\FrontendReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendReferenceContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_reference_pack_contains_fifty_safe_articles_in_two_categories(): void
    {
        $manifestPath = database_path('seeders/data/frontend-reference-v1/manifest.json');

        $this->assertFileExists($manifestPath);

        $manifest = File::json($manifestPath);
        $this->assertSame('frontend-reference-v1', $manifest['version'] ?? null);
        $this->assertSame('2.3.0', $manifest['release_version'] ?? null);
        $this->assertSame('geoflow-template-21-enterprise-signature', $manifest['default_theme'] ?? null);
        $this->assertCount(2, $manifest['categories'] ?? []);
        $this->assertCount(50, $manifest['articles'] ?? []);

        $slugs = [];
        $titles = [];
        $files = [];
        $categoryCounts = [];
        foreach ($manifest['articles'] as $article) {
            $slugs[] = $article['slug'] ?? null;
            $titles[] = $article['title'] ?? null;
            $files[] = $article['file'] ?? null;
            $categorySlug = $article['category_slug'] ?? '';
            $categoryCounts[$categorySlug] = ($categoryCounts[$categorySlug] ?? 0) + 1;

            $contentPath = dirname($manifestPath).'/'.($article['file'] ?? '');
            $this->assertFileExists($contentPath);

            $content = File::get($contentPath);
            $this->assertNotSame('', trim($content));
            $this->assertDoesNotMatchRegularExpression('/\b2\.1\.1\b|localhost|127\.0\.0\.1/i', $content);
            $this->assertDoesNotMatchRegularExpression('/(?:api[_-]?key|secret|password)\s*[:=]\s*[A-Za-z0-9_\-]{12,}/i', $content);
        }

        $this->assertCount(50, array_unique($slugs));
        $this->assertCount(50, array_unique($titles));
        $this->assertCount(50, array_unique($files));
        $this->assertSame([
            'geoflow-getting-started' => 35,
            'geoflow-deployment-operations' => 15,
        ], $categoryCounts);
    }

    public function test_reference_seeder_is_idempotent_and_preserves_existing_user_rows(): void
    {
        Config::set('geoflow.seed_frontend_demo_overwrite', false);

        $category = Category::query()->create([
            'slug' => 'geoflow-getting-started',
            'name' => '用户自定义分类',
            'description' => '保留我的分类说明',
            'sort_order' => 99,
        ]);
        $author = Author::query()->create([
            'name' => '用户作者',
            'email' => 'editor@geoflow.local',
            'bio' => '保留我的作者说明',
        ]);
        Article::query()->create([
            'slug' => 'gnflg8xg',
            'title' => '用户自定义文章',
            'excerpt' => '用户摘要',
            'content' => '用户正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        $this->seed(FrontendReferenceSeeder::class);
        $this->seed(FrontendReferenceSeeder::class);

        $this->assertSame(50, Article::query()->count());
        $this->assertSame(2, Category::query()->count());
        $this->assertSame('用户自定义分类', $category->fresh()->name);
        $this->assertSame('用户作者', $author->fresh()->name);
        $this->assertSame('用户自定义文章', Article::query()->where('slug', 'gnflg8xg')->value('title'));
    }
}
