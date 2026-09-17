<?php

namespace Tests\Feature;

use App\Jobs\CheckUrlChange;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\UrlChangeRequest;
use App\Services\Site\UrlChangeService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UrlChangeUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_report_renders_risk_confirmation_without_credentials_in_links(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $change = $this->ready($admin);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.url-changes.show', $change));

        $response->assertSee('查看风险并确认')->assertSee('确认修改文章链接')->assertSee('这些外部使用情况无法完整判断')
            ->assertSee('data-url-confirm disabled', false)->assertSee('data-url-risk-open disabled', false)
            ->assertSee('name="credential"', false)->assertDontSee('preview_credential=', false);
        $this->assertSame(0, preg_match('/href="[^"]*(?:credential|confirmation)=/', $response->getContent()));
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_report_uses_bound_english_phrase_and_escapes_article_titles(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        app()->setLocale('en');
        $admin = $this->admin();
        $change = $this->ready($admin, '<script>alert(1)</script>');

        $response = $this->actingAs($admin, 'admin')->withSession(['admin_locale' => 'en', 'locale' => 'en'])->get(route('admin.url-changes.show', $change));

        $response->assertSee('CHANGE ARTICLE URLS')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('data-url-risk-dialog', false);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_report_warns_about_ten_historical_rules_without_predicting_a_fixed_completion_time(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $change = app(UrlChangeService::class)->start($admin, 'primary', null, '/{category}/{slug}');
        $sites = $change->sites;
        $sites[0]['policy']['history'] = array_map(fn ($id) => ['pattern' => '/revision-'.$id.'/{slug}'], range(1, 10));
        $change->update(['sites' => $sites]);

        $this->actingAs($admin, 'admin')->get(route('admin.url-changes.show', $change))
            ->assertOk()->assertSee('保留 10 条历史规则')->assertSee('历史规则已达到 10 条')->assertSee('大量历史标识可能需要数小时');
    }

    public function test_checking_and_expired_reports_have_no_confirmation_form(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $change = app(UrlChangeService::class)->start($admin, 'primary', null, '/{category}/{slug}');

        $this->actingAs($admin, 'admin')->get(route('admin.url-changes.show', $change))->assertSee('正在检查影响')->assertDontSee('data-url-confirm-form', false);
        $change->forceFill(['status' => 'ready', 'nonce_hash' => hash('sha256', 'test'), 'expires_at' => now()->subMinute()])->save();
        $this->get(route('admin.url-changes.show', $change))->assertDontSee('data-url-confirm-form', false)->assertSee('重新检查影响');
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_existing_category_displays_readonly_slug_and_separate_super_admin_change_form(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.categories.edit', $category->id))
            ->assertSee('申请修改 URL 标识')->assertSee('name="operation" value="category"', false)->assertDontSee('name="slug"', false);
    }

    public function test_cancel_and_edit_releases_the_scope_and_restores_the_proposed_pattern(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $change = $this->ready($admin);

        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasInput('pattern', '/{category}/{slug}')
            ->assertSessionHas('site_settings_open_target', 'site-settings-permalink');

        $this->assertSame('cancelled', $change->refresh()->status);
        $this->assertDatabaseHas('url_change_scope_states', ['scope_key' => 'primary', 'active_request_id' => null]);
        $this->get(route('admin.site-settings.index'))
            ->assertSee('data-site-settings-open-target="site-settings-permalink"', false);
        $this->post(route('admin.url-changes.store'), ['operation' => 'primary', 'value' => '/{slug}.html'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('url_change_requests', 2);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_category_report_names_its_target_and_cancel_restores_the_slug_draft(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $category = Category::query()->create(['name' => 'Industry reports', 'slug' => 'reports']);
        $change = app(UrlChangeService::class)->start($admin, 'category', $category->id, 'industry-reports');
        for ($i = 0; $i < 20 && $change->refresh()->status === 'checking'; $i++) {
            app(UrlChangeService::class)->checkSegment($change);
        }

        $this->actingAs($admin, 'admin')->get(route('admin.url-changes.show', $change))
            ->assertSee('分类：Industry reports（ID '.$category->id.'）')->assertSee('取消检查并继续编辑')
            ->assertSee('其中 0 篇当前可公开访问')->assertSee('未来生效');
        $this->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])
            ->assertRedirect(route('admin.categories.edit', $category->id))->assertSessionHasInput('value', 'industry-reports');
        $this->get(route('admin.categories.edit', $category->id))->assertSee('value="industry-reports"', false);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'slug' => 'reports']);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_article_move_report_displays_article_and_both_category_names(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $oldCategory = Category::query()->create(['name' => 'Industry reports', 'slug' => 'reports']);
        $newCategory = Category::query()->create(['name' => 'Research notes', 'slug' => 'research']);
        $author = Author::query()->create(['name' => 'Editorial']);
        $article = Article::query()->create(['title' => 'Annual industry trends', 'slug' => 'annual-trends', 'content' => 'Article text', 'status' => 'published', 'category_id' => $oldCategory->id, 'author_id' => $author->id]);
        $change = app(UrlChangeService::class)->start($admin, 'article_category', $article->id, (string) $newCategory->id);
        for ($i = 0; $i < 20 && $change->refresh()->status === 'checking'; $i++) {
            app(UrlChangeService::class)->checkSegment($change);
        }

        $response = $this->actingAs($admin, 'admin')->get(route('admin.url-changes.show', $change));

        $response->assertSee('文章：Annual industry trends（ID '.$article->id.'）')
            ->assertSee('分类：Industry reports（ID '.$oldCategory->id.'）')
            ->assertSee('分类：Research notes（ID '.$newCategory->id.'）')->assertSee('data-url-risk-dialog', false);
        $this->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])
            ->assertRedirect(route('admin.articles.edit', $article->id))->assertSessionHasInput('category_id', (string) $newCategory->id);
        $this->assertDatabaseHas('articles', ['id' => $article->id, 'category_id' => $oldCategory->id]);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_cancel_and_edit_rejects_changes_that_are_already_applied(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $change = $this->ready($admin);
        $change->update(['status' => 'applied', 'applied_at' => now()]);

        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])->assertConflict();

        $this->assertSame('applied', $change->refresh()->status);
        $this->assertDatabaseHas('url_change_scope_states', ['scope_key' => 'primary', 'active_request_id' => $change->id]);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_cancel_hosted_check_returns_to_the_correct_site_with_its_pattern_draft(): void
    {
        Queue::fake([CheckUrlChange::class]);
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => 'Research', 'domain' => 'research.sites.test', 'endpoint_url' => 'https://research.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'research.sites.test', 'root_domain' => 'sites.test', 'topic' => 'Research', 'serving_status' => HostedSiteProfile::SERVING_ONLINE]);
        $change = app(UrlChangeService::class)->start($admin, 'hosted', $channel->id, '/{slug}.html');

        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])
            ->assertRedirect(route('admin.distribution.hosted-sites.edit', ['hostedSite' => $channel->id]))
            ->assertSessionHasInput('pattern', '/{slug}.html');

        $this->assertSame('cancelled', $change->refresh()->status);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_cancel_does_not_release_another_admins_report(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $owner = $this->admin();
        $change = $this->ready($owner);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])->assertForbidden();

        $this->assertSame('ready', $change->refresh()->status);
        $this->assertDatabaseHas('url_change_scope_states', ['scope_key' => 'primary', 'active_request_id' => $change->id]);
        Queue::assertPushed(CheckUrlChange::class);
    }

    public function test_busy_cancel_preserves_the_report_and_shows_retry_guidance(): void
    {
        Queue::fake([CheckUrlChange::class]);
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = $this->admin();
        $change = $this->ready($admin);
        Cache::partialMock()->shouldReceive('lock')->once()->with('url-change:'.$change->id, 120)->andThrow(new LockTimeoutException);

        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.cancel', $change), ['return_to_editor' => true])
            ->assertSessionHasErrors(['confirmation' => '后台正在保存本批检查结果，暂时无法取消。当前地址保持不变，请稍后重试“取消检查并继续编辑”。']);

        $this->assertSame('ready', $change->refresh()->status);
        Queue::assertPushed(CheckUrlChange::class);
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'url-ui-'.uniqid(), 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function ready(Admin $admin, string $title = 'Industry developments'): UrlChangeRequest
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editorial']);
        Article::query()->create(['title' => $title, 'slug' => 'industry-developments', 'content' => 'Article text', 'status' => 'published', 'category_id' => $category->id, 'author_id' => $author->id]);
        $change = app(UrlChangeService::class)->start($admin, 'primary', null, '/{category}/{slug}');
        for ($i = 0; $i < 20 && $change->refresh()->status === 'checking'; $i++) {
            app(UrlChangeService::class)->checkSegment($change);
        }
        $this->assertSame('ready', $change->refresh()->status, (string) $change->error);

        return $change;
    }
}
