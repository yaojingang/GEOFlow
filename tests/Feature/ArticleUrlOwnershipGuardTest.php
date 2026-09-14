<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Services\GeoFlow\ArticleSlugRegistry;
use App\Services\GeoFlow\CategorySlugRegistry;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\ArticleUrlOwnershipGuard;
use App\Services\Site\UrlChangeInspector;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleUrlOwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('oldCategoryStates')]
    public function test_preserves_a_composite_old_path_after_an_article_leaves_its_category(bool $deleteOldCategory): void
    {
        Queue::fake();
        $original = Category::query()->create(['name' => 'Original', 'slug' => 'x']);
        $destination = Category::query()->create(['name' => 'Destination', 'slug' => 'z']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        $existing = Article::query()->create(['id' => 1, 'title' => 'Existing', 'slug' => 'y', 'content' => 'A', 'category_id' => $original->id, 'author_id' => $author->id, 'status' => 'published']);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/fixed/{id}/{category}-{slug}')->activate('/fixed/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        $urls = app(ArticlePermalinkService::class);
        $this->assertSame('/fixed/1/x-y', $urls->path($existing, ArticlePermalinkPolicy::fromRaw(['current_pattern' => '/fixed/{id}/{category}-{slug}'])));
        $existing->update(['category_id' => $destination->id]);
        if ($deleteOldCategory) {
            app(CategorySlugRegistry::class)->rememberDeleted($original);
            $original->delete();
        }
        $this->assertSame($existing->id, $urls->resolve('/fixed/1/x-y')?->article->id);

        try {
            DB::transaction(function () use ($other, $author): void {
                $article = Article::query()->create(['id' => 2, 'title' => 'New', 'slug' => 'x-y', 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id, 'status' => 'published']);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('Moving an article must not release its old composite URL.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('article_permalink.errors.ambiguous_path', ['path' => '/fixed/1/x-y', 'articles' => '#1 / #2'])]], $exception->errors());
        }

        $this->assertDatabaseMissing('articles', ['id' => 2]);
        $this->assertSame($existing->id, $urls->resolve('/fixed/1/x-y')?->article->id);
        Queue::assertNothingPushed();
    }

    public static function oldCategoryStates(): array
    {
        return ['retained category' => [false], 'deleted category' => [true]];
    }

    public function test_preserves_a_composite_old_path_after_the_article_date_is_corrected(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        $existing = Article::query()->create(['id' => 1, 'title' => 'Existing', 'slug' => 'y', 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
        $existing->forceFill(['created_at' => '2020-02-03 00:00:00'])->save();
        $oldPattern = '/fixed/{id}/{year}-{month}-{day}-{slug}';
        $policy = ArticlePermalinkPolicy::defaults()->activate($oldPattern)->activate('/fixed/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        $urls = app(ArticlePermalinkService::class);
        $this->assertSame('/fixed/1/2020-02-03-y', $urls->path($existing, ArticlePermalinkPolicy::fromRaw(['current_pattern' => $oldPattern])));
        $existing->forceFill(['created_at' => '2026-09-13 00:00:00'])->save();
        $this->assertSame($existing->id, $urls->resolve('/fixed/1/2020-02-03-y')?->article->id);

        try {
            DB::transaction(function () use ($other, $author): void {
                $article = Article::query()->create(['id' => 2, 'title' => 'New', 'slug' => '2020-02-03-y', 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id, 'status' => 'published']);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('Correcting a date must not release the old composite URL.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('article_permalink.errors.ambiguous_path', ['path' => '/fixed/1/2020-02-03-y', 'articles' => '#1 / #2'])]], $exception->errors());
        }

        $this->assertDatabaseMissing('articles', ['id' => 2]);
        $this->assertSame($existing->id, $urls->resolve('/fixed/1/2020-02-03-y')?->article->id);
        Queue::assertNothingPushed();
    }

    public function test_allows_independent_category_segments_with_more_than_500_existing_articles(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        $rows = [];
        foreach (range(1, 501) as $id) {
            $rows[] = ['id' => $id, 'title' => 'Post '.$id, 'slug' => 'post-'.$id, 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'created_at' => '2026-09-13 00:00:00'];
        }
        DB::table('articles')->insert($rows);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/article/{category}/{slug}.html')->activate('/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);

        DB::transaction(function () use ($category, $author): void {
            $article = Article::query()->create(['id' => 502, 'title' => 'New', 'slug' => 'fresh', 'content' => 'B', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
            app(UrlChangeInspector::class)->assertArticleCompatible($article);
        });

        $urls = app(ArticlePermalinkService::class);
        $this->assertSame(1, $urls->resolve('/article/news/post-1.html')?->article->id);
        $this->assertSame(502, $urls->resolve('/news/fresh')?->article->id);
        Queue::assertNothingPushed();
    }

    public function test_refuses_more_than_500_locator_candidates_without_filtering_by_current_category(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        $rows = [];
        foreach (range(1, 501) as $id) {
            $rows[] = ['id' => $id, 'title' => 'Post '.$id, 'slug' => 'post-'.$id, 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'created_at' => '2026-09-13 00:00:00'];
        }
        DB::table('articles')->insert($rows);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/fixed/{category}/{id}')->activate('/fixed/{id}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);

        try {
            DB::transaction(function () use ($category, $author): void {
                $article = Article::query()->create(['id' => 502, 'title' => 'New', 'slug' => 'fresh', 'content' => 'B', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('Too many possible historical owners must cancel the write.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('url_change.errors.ownership_budget')]], $exception->errors());
        }

        $this->assertDatabaseMissing('articles', ['id' => 502]);
        Queue::assertNothingPushed();
    }

    public function test_refuses_an_unresolved_historical_category_and_year_intersection(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        Article::query()->create(['id' => 1, 'title' => 'Existing', 'slug' => '2', 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
        $article = Article::query()->create(['id' => 2, 'title' => 'New', 'slug' => 'fresh', 'content' => 'B', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/fixed/{category}/{slug}')->activate('/fixed/{year}/{id}');

        try {
            app(ArticleUrlOwnershipGuard::class)->assertAvailable($article, ['policy' => $policy->toArray()], Article::withTrashed());
            $this->fail('A current non-numeric category cannot prove historical year paths safe.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('url_change.errors.ownership_budget')]], $exception->errors());
        }

        Queue::assertNothingPushed();
    }

    public function test_rejects_a_slug_that_would_take_over_a_composite_historical_path(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'Source', 'slug' => 'x']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        $existing = Article::query()->create(['id' => 1, 'title' => 'Existing', 'slug' => 'y-z', 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/fixed/{id}/{category}-{slug}')->activate('/fixed/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        $urls = app(ArticlePermalinkService::class);
        $this->assertSame($existing->id, $urls->resolve('/fixed/1/x-y-z')?->article->id);

        try {
            DB::transaction(function () use ($other, $author): void {
                $article = Article::query()->create(['id' => 2, 'title' => 'New', 'slug' => 'x-y-z', 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id, 'status' => 'published']);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('A new article must not take over another article\'s historical path.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('article_permalink.errors.ambiguous_path', ['path' => '/fixed/1/x-y-z', 'articles' => '#1 / #2'])]], $exception->errors());
        }

        $this->assertSame($existing->id, $urls->resolve('/fixed/1/x-y-z')?->article->id);
    }

    public function test_rejects_an_encoded_old_slug_combined_with_a_historical_zero_category(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'Source', 'slug' => '0']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        $existing = Article::query()->create(['id' => 1, 'title' => 'Existing', 'slug' => '旧-文章%_', 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
        app(CategorySlugRegistry::class)->change($category, 'renamed');
        app(ArticleSlugRegistry::class)->change($existing, 'current');
        $policy = ArticlePermalinkPolicy::defaults()->activate('/fixed/{id}/{category}-{slug}')->activate('/fixed/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        $path = '/fixed/1/0-%E6%97%A7-%E6%96%87%E7%AB%A0%25_';
        $urls = app(ArticlePermalinkService::class);
        $this->assertSame($existing->id, $urls->resolve($path)?->article->id);

        try {
            DB::transaction(function () use ($other, $author): void {
                $article = Article::query()->create(['id' => 2, 'title' => 'New', 'slug' => '0-旧-文章%_', 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id, 'status' => 'published']);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('Encoded historical category and article slugs must retain their owner.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('article_permalink.errors.ambiguous_path', ['path' => $path, 'articles' => '#1 / #2'])]], $exception->errors());
        }

        $this->assertSame($existing->id, $urls->resolve($path)?->article->id);
    }

    public function test_allows_an_id_and_slug_preset_with_more_than_500_existing_articles(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        $rows = [];
        foreach (range(1, 501) as $id) {
            $rows[] = ['id' => $id, 'title' => 'Post '.$id, 'slug' => 'post-'.$id, 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'created_at' => '2026-09-13 00:00:00'];
        }
        DB::table('articles')->insert($rows);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/article/{id}-{slug}.html');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);

        DB::transaction(function () use ($category, $author): void {
            $article = Article::query()->create(['id' => 502, 'title' => 'New', 'slug' => 'fresh', 'content' => 'B', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
            app(UrlChangeInspector::class)->assertArticleCompatible($article);
        });

        $urls = app(ArticlePermalinkService::class);
        $this->assertSame(1, $urls->resolve('/article/1-post-1.html')?->article->id);
        $this->assertSame(502, $urls->resolve('/article/502-fresh.html')?->article->id);
    }

    public function test_rejects_a_write_when_composite_segment_splits_exceed_the_safety_budget(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/fixed/{category}1{id}1{slug}')->activate('/fixed/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);

        try {
            DB::transaction(function () use ($category, $author): void {
                $article = Article::query()->create(['id' => 1, 'title' => 'New', 'slug' => str_repeat('1', 80), 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('An exhausted safety budget must cancel the write.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('url_change.errors.ownership_budget')]], $exception->errors());
        }

        $this->assertNull(app(ArticlePermalinkService::class)->resolve('/fixed/'.str_repeat('1', 80)));
    }
}
