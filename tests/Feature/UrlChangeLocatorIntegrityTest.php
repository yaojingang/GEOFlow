<?php

namespace Tests\Feature;

use App\Jobs\CheckUrlChange;
use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Author;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Models\SiteSetting;
use App\Models\UrlChangeRequest;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\UrlChangeInspector;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlChangeLocatorIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_preview_rejects_a_broken_canonical_path_and_keeps_the_public_article_available(): void
    {
        Queue::fake([CheckUrlChange::class, RefreshUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article('my-post');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/prefix/{category}-{slug}'])
            ->assertSessionHasNoErrors();
        $change = $this->checked(UrlChangeRequest::query()->firstOrFail());

        $this->assertSame('failed', $change->status);
        $this->assertSame($this->error($article, '/prefix/news-my-post'), $change->error);
        $this->post(route('admin.url-changes.confirm', $change), ['credential' => 'invalid', 'confirmation' => '确认修改文章链接'])
            ->assertSessionHasErrors('confirmation');
        $this->assertNull($change->refresh()->applied_at);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY]);
        $this->get('/article/my-post')->assertOk()->assertSee('Locator article');
        Queue::assertPushed(CheckUrlChange::class);
        Queue::assertNotPushed(RefreshUrlChange::class);
    }

    #[DataProvider('categoryChanges')]
    public function test_checks_both_category_values_for_renames_and_article_moves(string $operation, string $oldSlug, string $nextSlug, string $badPath): void
    {
        $article = $this->article('story', $oldSlug);
        $nextCategory = Category::query()->create(['name' => 'Destination', 'slug' => $nextSlug]);
        $change = new UrlChangeRequest(['operation' => $operation, 'new_value' => $operation === 'category' ? $nextSlug : (string) $nextCategory->id]);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/prefix/{slug}-{category}');

        $errors = app(UrlChangeInspector::class)->conflicts($change, ['key' => 'primary', 'next_policy' => $policy->toArray()], [['article' => $article, 'slug' => $article->slug]]);

        $this->assertSame([$this->error($article, $badPath)], $errors);
        $this->assertSame($oldSlug, $article->category->fresh()->slug);
        $this->assertSame((int) $article->category_id, (int) $article->fresh()->category_id);
    }

    public static function categoryChanges(): array
    {
        return [
            'rename new category value' => ['category', 'news', 'industry-news', '/prefix/story-industry-news'],
            'rename old category value' => ['category', 'industry-news', 'news', '/prefix/story-industry-news'],
            'move new category value' => ['article_category', 'news', 'industry-news', '/prefix/story-industry-news'],
            'move old category value' => ['article_category', 'industry-news', 'news', '/prefix/story-industry-news'],
        ];
    }

    #[DataProvider('historicalValues')]
    public function test_background_check_validates_historical_article_and_category_values(string $historyType, string $pattern, string $path): void
    {
        Queue::fake([CheckUrlChange::class, RefreshUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article($historyType === 'rule' ? 'my-post' : 'story');
        if ($historyType === 'article') {
            ArticleSlugHistory::query()->create(['article_id' => $article->id, 'slug' => 'my-post', 'created_at' => now(), 'last_used_at' => now()]);
        } elseif ($historyType === 'category') {
            CategorySlugHistory::query()->create(['category_id' => $article->category_id, 'original_category_id' => $article->category_id, 'slug' => 'industry-news']);
        } else {
            $policy = ArticlePermalinkPolicy::defaults()->activate('/prefix/{category}-{slug}')->activate(ArticlePermalinkPolicy::DEFAULT_PATTERN);
            SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        }

        $change = $this->checked(app(UrlChangeService::class)->start($this->admin(), 'primary', null, $pattern));

        $this->assertSame('failed', $change->status);
        $this->assertSame($this->error($article, $path), $change->error);
        $this->assertNull($change->applied_at);
        Queue::assertPushed(CheckUrlChange::class);
        Queue::assertNotPushed(RefreshUrlChange::class);
    }

    public static function historicalValues(): array
    {
        return [
            'historical article slug' => ['article', '/prefix/{category}-{slug}', '/prefix/news-my-post'],
            'historical category slug' => ['category', '/prefix/{slug}-{category}', '/prefix/story-industry-news'],
            'historical rule' => ['rule', '/read/{slug}', '/prefix/news-my-post'],
        ];
    }

    #[DataProvider('ruleStates')]
    public function test_new_article_check_rolls_back_an_unresolvable_current_or_historical_path(bool $historical): void
    {
        $policy = ArticlePermalinkPolicy::defaults()->activate('/prefix/{category}-{slug}');
        if ($historical) {
            $policy = $policy->activate(ArticlePermalinkPolicy::DEFAULT_PATTERN);
        }
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        $articleId = null;

        try {
            DB::transaction(function () use (&$articleId): void {
                $article = $this->article('my-post');
                $articleId = $article->id;
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('An unresolvable canonical path must cancel article creation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug' => [__('url_change.errors.locator_mismatch', ['article' => $articleId, 'path' => '/prefix/news-my-post'])]], $exception->errors());
        }

        $this->assertDatabaseMissing('articles', ['slug' => 'my-post']);
    }

    public static function ruleStates(): array
    {
        return ['current rule' => [false], 'historical rule' => [true]];
    }

    public function test_composite_display_segments_keep_a_safe_immutable_id_locator(): void
    {
        $article = $this->article('my-post');
        $policy = ArticlePermalinkPolicy::defaults()->activate('/prefix/{id}/{category}-{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);

        app(UrlChangeInspector::class)->assertArticleCompatible($article);

        $urls = app(ArticlePermalinkService::class);
        $path = '/prefix/'.$article->id.'/news-my-post';
        $this->assertSame($path, $urls->path($article));
        $this->assertSame((int) $article->id, (int) $urls->resolve($path)?->article->id);
        $this->get($path)->assertOk()->assertSee('Locator article');
    }

    #[DataProvider('encodedPathLengths')]
    public function test_checks_the_encoded_request_path_length(int $prefixLength, bool $allowed): void
    {
        $article = $this->article(str_repeat('文', 85), str_repeat('😀', 100));
        $policy = ArticlePermalinkPolicy::fromRaw(['current_pattern' => '/'.str_repeat('p', $prefixLength).'/{category}/{slug}']);
        $change = new UrlChangeRequest(['operation' => 'primary']);
        $path = '/'.str_repeat('p', $prefixLength).'/'.rawurlencode(str_repeat('😀', 100)).'/'.rawurlencode(str_repeat('文', 85));

        $errors = app(UrlChangeInspector::class)->conflicts($change, ['key' => 'primary', 'next_policy' => $policy->toArray()], [['article' => $article, 'slug' => $article->slug]]);

        $this->assertSame($allowed ? 2048 : 2049, strlen($path));
        $this->assertSame($allowed ? [] : [__('url_change.errors.request_path_invalid', ['article' => $article->id, 'length' => 2049])], $errors);
        if ($allowed) {
            SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
            $this->get($path)->assertOk()->assertSee('Locator article');
        } else {
            $this->assertNull(app(ArticlePermalinkService::class)->resolve($path));
        }
    }

    public static function encodedPathLengths(): array
    {
        return ['maximum length accepted' => [80, true], 'one byte too long rejected' => [81, false]];
    }

    public function test_an_overlong_historical_rule_is_checked_after_a_shorter_current_rule(): void
    {
        $article = $this->article(str_repeat('文', 85), str_repeat('😀', 100));
        $policy = ArticlePermalinkPolicy::fromRaw([
            'current_pattern' => '/short/{category}/{slug}',
            'history' => [['pattern' => '/'.str_repeat('p', 81).'/{category}/{slug}', 'retired_at' => '2026-09-13T00:00:00Z']],
        ]);
        $path = '/'.str_repeat('p', 81).'/'.rawurlencode(str_repeat('😀', 100)).'/'.rawurlencode(str_repeat('文', 85));

        $errors = app(UrlChangeInspector::class)->conflicts(new UrlChangeRequest(['operation' => 'primary']), ['key' => 'primary', 'next_policy' => $policy->toArray()], [['article' => $article, 'slug' => $article->slug]]);

        $this->assertSame(2049, strlen($path));
        $this->assertSame([__('url_change.errors.request_path_invalid', ['article' => $article->id, 'length' => 2049])], $errors);
    }

    public function test_overlong_rule_preview_preserves_the_existing_public_url_for_a_legacy_unicode_category(): void
    {
        Queue::fake([CheckUrlChange::class, RefreshUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article(str_repeat('文', 85), str_repeat('😀', 100));

        $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/'.str_repeat('p', 81).'/{category}/{slug}'])
            ->assertSessionHasNoErrors();
        $change = $this->checked(UrlChangeRequest::query()->firstOrFail());

        $this->assertSame('failed', $change->status);
        $this->assertSame(__('url_change.errors.request_path_invalid', ['article' => $article->id, 'length' => 2049]), $change->error);
        $this->assertNull($change->applied_at);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY]);
        $this->get('/article/'.rawurlencode($article->slug))->assertOk()->assertSee('Locator article');
        Queue::assertPushed(CheckUrlChange::class);
        Queue::assertNotPushed(RefreshUrlChange::class);
    }

    #[DataProvider('unstableDisplaySegments')]
    public function test_historical_display_segments_are_validated_even_with_an_unchanged_id_locator(string $current, string $historical, string $slug, string $category, string $path): void
    {
        $article = $this->article($slug, $category);
        $policy = ArticlePermalinkPolicy::fromRaw([
            'current_pattern' => $current,
            'history' => [['pattern' => $historical, 'retired_at' => '2026-09-13T00:00:00Z']],
        ]);

        $errors = app(UrlChangeInspector::class)->conflicts(new UrlChangeRequest(['operation' => 'primary']), ['key' => 'primary', 'next_policy' => $policy->toArray()], [['article' => $article, 'slug' => $article->slug]]);

        $this->assertSame([$this->error($article, $path)], $errors);
    }

    public static function unstableDisplaySegments(): array
    {
        return [
            'different composite separators' => ['/first/{id}/{category}-{slug}', '/other/{id}/{category}.{slug}', 'my..', 'news', '/other/1/news.my..'],
            'composite result cannot validate separate segments' => ['/first/{id}/{category}-{slug}', '/other/{id}/{category}/{slug}', 'story-post', '.', '/other/1/./story-post'],
        ];
    }

    private function checked(UrlChangeRequest $change): UrlChangeRequest
    {
        for ($step = 0; $step < 20 && $change->refresh()->status === 'checking'; $step++) {
            app(UrlChangeService::class)->checkSegment($change);
        }

        return $change->refresh();
    }

    private function error(Article $article, string $path): string
    {
        return __('url_change.errors.locator_mismatch', ['article' => $article->id, 'path' => $path]);
    }

    private function article(string $slug, string $categorySlug = 'news'): Article
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => $categorySlug]);
        $author = Author::query()->create(['name' => 'Author']);

        return Article::query()->create([
            'title' => 'Locator article', 'slug' => $slug, 'content' => 'Body',
            'category_id' => $category->id, 'author_id' => $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'locator_admin', 'password' => 'Password123!', 'email' => 'locator@example.test', 'role' => 'super_admin', 'status' => 'active']);
    }
}
