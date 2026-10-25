<?php

namespace Tests\Feature;

use App\Http\Controllers\Site\CategoryController;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\GeoFlow\CategorySlugRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CategorySlugHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_category_get_and_head_redirect_to_the_new_url_with_encoded_query_without_article_views(): void
    {
        $category = $this->category('ai-news');
        $article = $this->article($category);
        $admin = Admin::query()->create([
            'username' => 'category_history_admin',
            'password' => 'Password123!',
            'email' => 'category-history@example.test',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->freezeTime();

        app(CategorySlugRegistry::class)->change($category, ' Industry-News ', (int) $admin->id);

        $baseUrl = rtrim((string) config('app.url'), '/');
        $this->get('/category/ai-news?page=2&utm_source=a%26b%3F%23%0D%0A')
            ->assertStatus(301)
            ->assertRedirect($baseUrl.'/category/industry-news?page=2&utm_source=a%26b%3F%23%0D%0A');
        $this->head('/category/ai-news?page=2')
            ->assertStatus(301)
            ->assertRedirect($baseUrl.'/category/industry-news?page=2');
        $this->get('/category/industry-news')->assertSee('Category article');
        $this->assertSame(0, $article->fresh()->view_count);
        $this->assertDatabaseHas('category_slug_histories', [
            'slug' => 'ai-news',
            'category_id' => $category->id,
            'original_category_id' => $category->id,
            'admin_id' => $admin->id,
            'last_used_at' => now()->toDateTimeString(),
        ]);
    }

    #[DataProvider('rawQueries')]
    public function test_old_category_redirect_preserves_duplicate_query_keys_order_and_raw_encoding(string $query): void
    {
        $category = $this->category('old-name');
        $this->article($category);
        app(CategorySlugRegistry::class)->change($category, 'current-name');

        $this->get('/category/old-name?'.$query)
            ->assertStatus(301)
            ->assertRedirect(rtrim((string) config('app.url'), '/').'/category/current-name?'.$query);
    }

    /** @return array<string,array{string}> */
    public static function rawQueries(): array
    {
        return [
            'duplicate and dotted keys' => ['utm_source=one&utm_source=two&filter.tag=x&page=2'],
            'encoding case and spaces' => ['z=%2f+%20&x=%7e&a=%252F&z=%2F'],
            'empty and valueless parameters' => ['flag&empty=&x=1&&x=2'],
        ];
    }

    public function test_old_category_redirect_rejects_raw_query_header_controls(): void
    {
        $category = $this->category('old-name');
        $this->article($category);
        app(CategorySlugRegistry::class)->change($category, 'current-name');
        $request = new Request(server: [
            'REQUEST_URI' => "/category/old-name?q=value\r\nLocation:https://example.test",
            'REQUEST_METHOD' => 'GET',
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(CategoryController::class)->show($request, 'old-name');
    }

    public function test_successive_renames_redirect_each_old_slug_directly_to_the_latest_category(): void
    {
        $category = $this->category('first-name');
        $this->article($category);
        $registry = app(CategorySlugRegistry::class);
        $registry->change($category, 'second-name');

        $registry->change($category, 'latest-name');

        $baseUrl = rtrim((string) config('app.url'), '/');
        $this->get('/category/first-name')->assertStatus(301)->assertRedirect($baseUrl.'/category/latest-name');
        $this->get('/category/second-name')->assertStatus(301)->assertRedirect($baseUrl.'/category/latest-name');
        $this->get('/category/latest-name')->assertSee('Category article');
        $this->assertDatabaseCount('category_slug_histories', 2);
    }

    public function test_a_category_can_return_to_its_own_historical_slug_without_a_redirect_loop(): void
    {
        $category = $this->category('original-name');
        $this->article($category);
        $registry = app(CategorySlugRegistry::class);
        $registry->change($category, 'second-name');

        $registry->change($category, 'original-name');

        $baseUrl = rtrim((string) config('app.url'), '/');
        $this->get('/category/original-name')->assertSee('Category article');
        $this->get('/category/second-name')->assertStatus(301)->assertRedirect($baseUrl.'/category/original-name');
        $this->assertDatabaseHas('category_slug_histories', [
            'slug' => 'second-name',
            'original_category_id' => $category->id,
        ]);
        $this->assertDatabaseHas('category_slug_histories', [
            'slug' => 'original-name',
            'original_category_id' => $category->id,
        ]);
    }

    #[DataProvider('occupiedSlugs')]
    public function test_another_category_cannot_take_a_current_or_historical_slug(string $slug, string $suggestion): void
    {
        $owner = $this->category('old-name');
        $other = $this->category('other-name');
        $registry = app(CategorySlugRegistry::class);
        $registry->change($owner, 'current-name');

        try {
            $registry->change($other, $slug);
            $this->fail('An occupied category slug was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'slug' => [__('url_change.errors.category_slug_unavailable', ['suggestion' => $suggestion])],
            ], $exception->errors());
        }

        $this->assertSame('other-name', $other->fresh()->slug);
        $this->assertDatabaseCount('category_slug_histories', 1);
        $this->assertDatabaseHas('category_slug_histories', ['slug' => 'old-name', 'category_id' => $owner->id]);
    }

    /** @return array<string,array{string,string}> */
    public static function occupiedSlugs(): array
    {
        return [
            'current slug' => ['current-name', 'current-name-2'],
            'historical slug' => ['old-name', 'old-name-2'],
        ];
    }

    public function test_generated_slugs_skip_current_historical_and_reserved_owners_and_fit_the_database_limit(): void
    {
        $category = $this->category('news');
        $this->category('news-2');
        $this->category(str_repeat('a', 100));
        $registry = app(CategorySlugRegistry::class);
        $registry->change($category, 'renamed');

        $generated = DB::transaction(fn (): array => [
            $registry->generate('News'),
            $registry->generate('Api'),
            $registry->generate(str_repeat('a', 110)),
        ]);

        $this->assertSame(['news-3', 'api-2', str_repeat('a', 98).'-2'], $generated);
    }

    public function test_deleted_category_keeps_current_and_historical_slug_tombstones_with_no_redirect_target(): void
    {
        $category = $this->category('old-name');
        $originalId = (int) $category->id;
        $registry = app(CategorySlugRegistry::class);
        $registry->change($category, 'last-name');

        DB::transaction(function () use ($category, $registry): void {
            $registry->rememberDeleted($category);
            $category->delete();
        });

        $this->get('/category/old-name')->assertNotFound();
        $this->get('/category/last-name')->assertNotFound();
        $this->assertDatabaseCount('category_slug_histories', 2);
        $this->assertDatabaseHas('category_slug_histories', [
            'slug' => 'old-name', 'category_id' => null, 'original_category_id' => $originalId,
        ]);
        $this->assertDatabaseHas('category_slug_histories', [
            'slug' => 'last-name', 'category_id' => null, 'original_category_id' => $originalId,
        ]);
        $this->assertSame('old-name-2', $registry->generate('Old Name'));
        $this->assertSame('last-name-2', $registry->generate('Last Name'));

        $this->expectException(ValidationException::class);
        $registry->assertAvailable('old-name', $originalId);
    }

    public function test_deletion_rollback_keeps_the_category_and_its_existing_history(): void
    {
        $category = $this->category('old-name');
        $registry = app(CategorySlugRegistry::class);
        $registry->change($category, 'current-name');

        DB::beginTransaction();
        $registry->rememberDeleted($category);
        $category->delete();
        DB::rollBack();

        $this->assertModelExists($category);
        $this->assertDatabaseCount('category_slug_histories', 1);
        $this->assertDatabaseHas('category_slug_histories', ['slug' => 'old-name', 'category_id' => $category->id]);
    }

    public function test_legacy_unicode_slug_remains_readable_and_redirects_after_an_explicit_rename(): void
    {
        $category = $this->category('行业资讯');
        $this->article($category);
        $this->get('/category/'.rawurlencode('行业资讯'))->assertSee('Category article');

        app(CategorySlugRegistry::class)->change($category, 'industry-news');

        $this->get('/category/'.rawurlencode('行业资讯'))
            ->assertStatus(301)
            ->assertRedirect(rtrim((string) config('app.url'), '/').'/category/industry-news');
        $this->assertDatabaseHas('category_slug_histories', ['slug' => '行业资讯', 'category_id' => $category->id]);
    }

    public function test_same_slug_change_keeps_history_empty(): void
    {
        $category = $this->category('same-name');

        app(CategorySlugRegistry::class)->change($category, ' SAME-NAME ');

        $this->assertSame('same-name', $category->fresh()->slug);
        $this->assertDatabaseCount('category_slug_histories', 0);
    }

    public function test_category_page_provides_article_card_summaries(): void
    {
        $category = $this->category('category-summary');
        $article = $this->article($category);

        $this->get('/category/category-summary')
            ->assertOk()
            ->assertViewHas('cardSummaries', [$article->id => 'Category article body']);
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_explicit_slugs_return_an_actionable_field_error(string $slug): void
    {
        try {
            app(CategorySlugRegistry::class)->normalize($slug);
            $this->fail('An invalid explicit category slug was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'slug' => [__('url_change.errors.category_slug_invalid')],
            ], $exception->errors());
        }
    }

    /** @return array<string,array{string}> */
    public static function invalidSlugs(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'overlong' => [str_repeat('a', 101)],
            'non ASCII' => ['行业资讯'],
            'underscore' => ['industry_news'],
            'path' => ['industry/news'],
            'encoded separator' => ['industry%2fnews'],
            'control character' => ["industry\nnews"],
        ];
    }

    public function test_explicit_reserved_slug_is_rejected_without_changing_the_category(): void
    {
        $category = $this->category('existing-name');

        try {
            app(CategorySlugRegistry::class)->change($category, 'api');
            $this->fail('A reserved category slug was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'slug' => [__('article_permalink.errors.category_reserved_path', ['slug' => 'api', 'path' => 'api'])],
            ], $exception->errors());
        }

        $this->assertSame('existing-name', $category->fresh()->slug);
        $this->assertDatabaseCount('category_slug_histories', 0);
    }

    public function test_old_and_current_slugs_return_404_after_the_last_visible_article_is_hidden(): void
    {
        $category = $this->category('old-name');
        $article = $this->article($category);
        app(CategorySlugRegistry::class)->change($category, 'current-name');
        $article->update(['status' => 'draft']);

        $this->get('/category/old-name')->assertNotFound();
        $this->get('/category/current-name')->assertNotFound();
    }

    public function test_old_slug_redirects_only_on_the_host_where_its_category_is_visible(): void
    {
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.primary_hosts', ['primary.test', 'localhost']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
        $category = $this->category('alpha-old');
        $alpha = $this->hostedSite('alpha');
        $this->hostedSite('beta');
        $task = Task::query()->create([
            'name' => 'Hosted category task', 'status' => 'active', 'publish_scope' => 'distribution_only',
        ]);
        $article = $this->article($category, ['task_id' => $task->id, 'status' => 'private']);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id,
            'hosted_site_profile_id' => $alpha->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', 'alpha-category'),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);

        app(CategorySlugRegistry::class)->change($category, 'alpha-current');

        $this->get('http://alpha.sites.test/category/alpha-old?page=2')
            ->assertStatus(301)->assertRedirect('https://alpha.sites.test/category/alpha-current?page=2');
        $this->get('http://beta.sites.test/category/alpha-old')->assertNotFound();
        $this->get('http://beta.sites.test/category/alpha-current')->assertNotFound();
        $this->get('http://primary.test/category/alpha-old')->assertNotFound();
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_encoded_category_paths_return_404(string $slug, string $encodedSlug): void
    {
        $category = $this->category('safe-category');
        $this->article($category);
        CategorySlugHistory::query()->create([
            'slug' => $slug, 'category_id' => $category->id, 'original_category_id' => $category->id,
        ]);

        $this->get('/category/'.$encodedSlug)->assertNotFound();
    }

    /** @return array<string,array{string,string}> */
    public static function unsafePaths(): array
    {
        return [
            'backslash' => ['unsafe\\name', 'unsafe%5Cname'],
            'encoded slash' => ['unsafe/name', 'unsafe%2Fname'],
            'control character' => ["unsafe\rname", 'unsafe%0Dname'],
            'invalid escape' => ['unsafe%zzname', 'unsafe%zzname'],
        ];
    }

    private function category(string $slug): Category
    {
        return Category::query()->create(['name' => 'Category '.$slug, 'slug' => $slug]);
    }

    /** @param array<string,mixed> $attributes */
    private function article(Category $category, array $attributes = []): Article
    {
        $author = Author::query()->create(['name' => 'Category author', 'email' => 'category-author@example.test']);

        return Article::query()->create(array_merge([
            'title' => 'Category article',
            'slug' => 'category-article',
            'content' => 'Category article body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ], $attributes));
    }

    private function hostedSite(string $label): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create([
            'name' => ucfirst($label),
            'domain' => $label.'.sites.test',
            'endpoint_url' => 'https://'.$label.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => ['site_name' => ucfirst($label)],
        ]);

        return HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => $label.'.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
    }
}
