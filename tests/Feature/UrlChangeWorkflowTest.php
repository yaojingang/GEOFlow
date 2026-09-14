<?php

namespace Tests\Feature;

use App\Exceptions\UrlChangeReportException;
use App\Jobs\CheckUrlChange;
use App\Jobs\RecoverUrlChanges;
use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\UrlChangeRequest;
use App\Services\GeoFlow\CategorySlugRegistry;
use App\Services\Site\UrlChangeReportStore;
use App\Services\Site\UrlChangeService;
use App\Services\Site\UrlChangeVersions;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UrlChangeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_check_is_queued_and_requires_typed_confirmation(): void
    {
        Queue::fake([CheckUrlChange::class, RefreshUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $this->article();

        $this->actingAs($admin, 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/{category}/{slug}'])
            ->assertRedirect(route('admin.url-changes.show', UrlChangeRequest::query()->firstOrFail()));
        Queue::assertPushed(CheckUrlChange::class);
        $change = $this->ready(UrlChangeRequest::query()->firstOrFail());
        $this->assertSame(1, $change->summary['changed_urls']);
        $credential = app(UrlChangeService::class)->credential($change);
        $this->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => 'yes'])->assertSessionHasErrors('confirmation');
        $this->assertNull($change->refresh()->applied_at);

        $this->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => '确认修改文章链接'])->assertSessionHasNoErrors();
        $this->assertNotNull($change->refresh()->applied_at);
        $policy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'));
        $this->assertSame('/{category}/{slug}', $policy->currentPattern);
        Queue::assertPushed(RefreshUrlChange::class);

        $this->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => '确认修改文章链接'])->assertSessionHasNoErrors();
        $this->assertSame(1, ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'))->revision);
    }

    public function test_bulk_publication_changes_invalidate_a_ready_report(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $article = $this->article();
        $service = app(UrlChangeService::class);
        $change = $this->ready($service->start($admin, 'primary', null, '/{category}/{slug}'));
        $credential = $service->credential($change);

        DB::table('articles')->where('id', $article->id)->update(['status' => 'draft']);

        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => '确认修改文章链接'])->assertSessionHasErrors('confirmation');
        $this->assertSame('stale', $change->refresh()->status);
        $this->assertNull($change->applied_at);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_structured_settings_count_resolves_custom_local_urls_and_counts_records_once(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $this->article();
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode(ArticlePermalinkPolicy::defaults()->activate('/{category}/{slug}')->toArray())]);
        SiteSetting::query()->create(['setting_key' => 'homepage_modules', 'setting_value' => json_encode([['url' => '/news/example'], ['url' => '/news/example?campaign=a']])]);
        SiteSetting::query()->create(['setting_key' => 'article_detail_ads', 'setting_value' => json_encode([['url' => '/article/example']])]);
        SiteSetting::query()->create(['setting_key' => 'article_detail_text_ads', 'setting_value' => json_encode([['url' => 'https://external.test/news/example'], ['url' => '/news/missing']])]);
        SiteSetting::query()->create(['setting_key' => 'site_description', 'setting_value' => 'Text containing /article/example']);

        $change = $this->ready(app(UrlChangeService::class)->start($this->admin(), 'primary', null, '/news/{id}.html'));

        $this->assertSame(2, $change->summary['structured_settings']);
        $this->assertSame('/news/example', json_decode(SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true)[0]['url']);
    }

    public function test_category_page_setting_is_counted_when_article_addresses_do_not_change(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article();
        SiteSetting::query()->create(['setting_key' => 'homepage_modules', 'setting_value' => json_encode([['url' => '/category/news']])]);
        SiteSetting::query()->create(['setting_key' => 'article_detail_ads', 'setting_value' => json_encode([['url' => '/article/example']])]);

        $change = $this->ready(app(UrlChangeService::class)->start($this->admin(), 'category', $article->category_id, 'renamed'));

        $this->assertSame(0, $change->summary['changed_urls']);
        $this->assertSame(1, $change->summary['structured_settings']);
    }

    public function test_rule_check_protects_a_historical_address_after_its_article_moved_category(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article();
        $article->update(['slug' => 'y']);
        $oldCategory = $article->category;
        $oldCategory->update(['slug' => 'x']);
        $newCategory = Category::query()->create(['name' => 'New', 'slug' => 'z']);
        $article->update(['category_id' => $newCategory->id]);
        Article::query()->create(['title' => 'Competing', 'slug' => 'x-y', 'content' => 'Body', 'category_id' => $newCategory->id, 'author_id' => $article->author_id, 'status' => 'published']);
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode(ArticlePermalinkPolicy::defaults()->activate('/fixed/{id}/{category}-{slug}')->toArray())]);
        $service = app(UrlChangeService::class);
        $change = $service->start($this->admin(), 'primary', null, '/fixed/{category}/{slug}');
        for ($step = 0; $step < 20 && $change->refresh()->status === 'checking'; $step++) {
            $service->checkSegment($change);
        }

        $this->assertSame('failed', $change->refresh()->status);
        $this->assertStringContainsString('/fixed/1/x-y', $change->error);
        $this->assertNull($change->applied_at);
    }

    public function test_settings_count_includes_older_category_aliases_and_excludes_the_proposed_address(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article();
        app(CategorySlugRegistry::class)->change($article->category, 'current');
        SiteSetting::query()->create(['setting_key' => 'homepage_modules', 'setting_value' => json_encode([['url' => '/category/news']])]);
        SiteSetting::query()->create(['setting_key' => 'article_detail_ads', 'setting_value' => json_encode([['url' => '/category/proposed']])]);

        $change = $this->ready(app(UrlChangeService::class)->start($this->admin(), 'category', $article->category_id, 'proposed'));

        $this->assertSame(1, $change->summary['structured_settings']);
    }

    public function test_content_and_view_count_do_not_invalidate_url_versions(): void
    {
        $article = $this->article();
        $versions = app(UrlChangeVersions::class);
        $before = $versions->snapshot(['primary']);

        DB::table('articles')->where('id', $article->id)->update(['content' => 'Updated text', 'view_count' => 8]);

        $this->assertSame($before, $versions->snapshot(['primary']));
    }

    public function test_category_report_includes_drafts_and_trash_without_claiming_public_usage(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $article = $this->article('draft');
        $article->delete();
        $service = app(UrlChangeService::class);

        $change = $this->ready($service->start($this->admin(), 'category', $article->category_id, 'industry-news'));

        $this->assertSame(1, $change->summary['associated']);
        $this->assertSame(1, $change->summary['trashed']);
        $this->assertSame(0, $change->summary['public_urls']);
        $this->assertSame(0, $change->summary['changed_urls']);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_regular_admin_cannot_start_or_read_url_reports(): void
    {
        $admin = $this->admin('admin');

        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.store'), ['operation' => 'primary', 'value' => '/{category}/{slug}'])->assertForbidden();
        $this->assertDatabaseCount('url_change_requests', 0);
    }

    public function test_sync_queue_fails_closed_without_changing_urls(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.url-changes.store'), ['operation' => 'primary', 'value' => '/{category}/{slug}'])->assertSessionHasErrors('value');

        $this->assertDatabaseCount('url_change_requests', 0);
    }

    public function test_explicit_reserved_category_slug_is_rejected_without_silent_suffix(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.categories.store'), ['name' => 'API', 'slug' => 'api'])->assertSessionHasErrors('slug');
        $this->assertDatabaseMissing('categories', ['name' => 'API']);
    }

    public function test_category_name_edit_preserves_slug_and_direct_slug_changes_are_forbidden(): void
    {
        $article = $this->article();
        $admin = $this->admin('admin');
        $this->actingAs($admin, 'admin')->put(route('admin.categories.update', $article->category_id), ['name' => 'Renamed display'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('categories', ['id' => $article->category_id, 'slug' => 'news']);
        $this->put(route('admin.categories.update', $article->category_id), ['name' => 'Another name', 'slug' => 'changed'])->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Another name']);
    }

    public function test_storage_quota_failure_preserves_urls_and_releases_the_scope(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $this->article();
        $service = app(UrlChangeService::class);
        $change = $service->start($this->admin(), 'primary', null, '/{category}/{slug}');
        $progress = $change->progress;
        $progress['phase'] = 'articles';
        $progress['bytes'] = 2 * 1024 ** 3;
        $change->update(['progress' => $progress]);
        $job = new CheckUrlChange($change->id);
        try {
            $job->handle($service);
            $this->fail('The report budget must stop the check.');
        } catch (UrlChangeReportException $exception) {
            $job->failed($exception);
        }
        $this->assertSame('failed', $change->refresh()->status);
        $this->assertStringContainsString('2 GiB', $change->error);
        $this->assertNull($change->applied_at);
        $this->assertNull(DB::table('url_change_scope_states')->where('scope_key', 'primary')->value('active_request_id'));
        $this->assertDatabaseMissing('site_settings', ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY]);
    }

    public function test_idle_check_is_recovered_and_does_not_duplicate_report_rows(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $first = $this->article();
        $rows = [];
        for ($id = 2; $id <= 501; $id++) {
            $rows[] = ['id' => $id, 'title' => 'Article '.$id, 'slug' => 'article-'.$id, 'content' => 'Body', 'status' => 'published', 'category_id' => $first->category_id, 'author_id' => $first->author_id];
        }
        DB::table('articles')->insert($rows);
        $service = app(UrlChangeService::class);
        $change = $service->start($this->admin(), 'primary', null, '/{category}/{slug}');
        $service->checkSegment($change);
        $service->checkSegment($change->refresh());
        $this->assertSame(500, $change->refresh()->progress['rows']);
        $change->timestamps = false;
        $change->forceFill(['updated_at' => now()->subMinutes(3)])->save();
        Queue::fake();
        app()->call([new RecoverUrlChanges, 'handle']);
        Queue::assertPushed(CheckUrlChange::class, fn ($job) => $job->changeId === $change->id);
        $this->ready($change);
        $this->assertSame(501, $change->refresh()->progress['rows']);
        $this->assertCount(501, iterator_to_array(app(UrlChangeReportStore::class)->rows($change), false));
    }

    private function ready(UrlChangeRequest $change): UrlChangeRequest
    {
        for ($i = 0; $i < 20 && $change->refresh()->status === 'checking'; $i++) {
            app(UrlChangeService::class)->checkSegment($change);
        }
        $this->assertSame('ready', $change->refresh()->status, (string) $change->error);

        return $change;
    }

    private function admin(string $role = 'super_admin'): Admin
    {
        return Admin::query()->create(['username' => 'url-risk-'.uniqid(), 'password' => 'password', 'role' => $role, 'status' => 'active']);
    }

    private function article(string $status = 'published'): Article
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);

        return Article::query()->create(['title' => 'Example', 'slug' => 'example', 'content' => 'Example content', 'status' => $status, 'category_id' => $category->id, 'author_id' => $author->id]);
    }
}
