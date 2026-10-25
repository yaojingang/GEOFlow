<?php

namespace Tests\Feature;

use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Services\Site\UrlChangeInspector;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ChecksUrlChanges;
use Tests\TestCase;

class UrlChangeSecurityTest extends TestCase
{
    use ChecksUrlChanges;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUrlQueue();
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_username');
            $table->string('admin_role');
            $table->string('action');
            $table->string('request_method');
            $table->string('page');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address');
            $table->text('details');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_confirmation_is_bound_to_owner_and_payload_and_never_logged(): void
    {
        $admin = $this->admin();
        $service = app(UrlChangeService::class);
        $service->start($admin, 'primary', null, '/{slug}.html');
        $change = $this->checkedChange();
        $credential = $service->credential($change);
        $other = $this->admin();
        $this->actingAs($other, 'admin')->get(route('admin.url-changes.show', $change))->assertForbidden();
        $this->get(route('admin.url-changes.download', $change))->assertForbidden();
        $this->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => $service->phrase($change)])->assertForbidden();
        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $credential.'tampered', 'confirmation' => $service->phrase($change)])->assertSessionHasErrors('confirmation');
        $this->assertNull($change->refresh()->applied_at);
        $details = AdminActivityLog::query()->pluck('details')->implode(' ');
        $this->assertStringNotContainsString($credential, $details);
        $this->assertStringNotContainsString($service->phrase($change), $details);
    }

    public function test_expired_confirmation_and_recheck_preserve_old_rule(): void
    {
        $admin = $this->admin();
        $service = app(UrlChangeService::class);
        $service->start($admin, 'primary', null, '/{slug}.html');
        $change = $this->checkedChange();
        $credential = $service->credential($change);
        $this->travel(16)->minutes();
        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => $service->phrase($change)])->assertSessionHasErrors('confirmation');
        $this->get(route('admin.url-changes.download', $change))->assertStatus(409);
        $retry = $service->start($admin, 'primary', null, '/{slug}.html');
        $this->assertNotSame($change->id, $retry->id);
        $this->assertSame('stale', $change->refresh()->status);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY]);
    }

    public function test_admin_demotion_and_auth_version_rotation_invalidate_credentials(): void
    {
        $admin = $this->admin();
        $service = app(UrlChangeService::class);
        $service->start($admin, 'primary', null, '/{slug}.html');
        $change = $this->checkedChange();
        $credential = $service->credential($change);
        DB::table('admins')->where('id', $admin->id)->update(['role' => 'admin']);
        $this->actingAs($admin->fresh(), 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => $service->phrase($change)])->assertForbidden();
        DB::table('admins')->where('id', $admin->id)->update(['role' => 'super_admin', 'auth_version' => $admin->auth_version + 1]);
        $this->actingAs($admin->fresh(), 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => $service->phrase($change)])->assertSessionHasErrors('confirmation');
        $this->assertNull($change->refresh()->applied_at);
    }

    public function test_cancelled_report_cannot_be_applied_and_late_failure_cannot_undo_applied_state(): void
    {
        $admin = $this->admin();
        $service = app(UrlChangeService::class);
        $service->start($admin, 'primary', null, '/{slug}.html');
        $change = $this->checkedChange();
        $credential = $service->credential($change);
        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.cancel', $change))->assertRedirect();
        $this->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => $service->phrase($change)])->assertSessionHasErrors('confirmation');
        $service->start($admin, 'primary', null, '/{slug}.html');
        $next = $this->checkedChange();
        $staleInstance = clone $next;
        $service->confirm($admin, $next, $service->credential($next), $service->phrase($next));
        $service->finish($staleInstance, 'failed');
        $this->assertSame('applied', $next->refresh()->status);
    }

    public function test_independent_hosted_reports_do_not_invalidate_each_other(): void
    {
        $admin = $this->admin();
        $channels = [];
        foreach (['alpha', 'beta'] as $label) {
            $channel = DistributionChannel::query()->create(['name' => $label, 'domain' => $label.'.sites.test', 'endpoint_url' => 'https://'.$label.'.sites.test', 'channel_type' => 'hosted_site', 'status' => 'active']);
            HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => $label.'.sites.test', 'root_domain' => 'sites.test', 'topic' => 'News', 'serving_status' => 'online']);
            $channels[] = $channel;
        }
        $service = app(UrlChangeService::class);
        $service->start($admin, 'hosted', $channels[0]->id, '/{slug}.html');
        $first = $this->checkedChange();
        $second = $service->start($admin, 'hosted', $channels[1]->id, '/{category}/{slug}');
        while ($second->refresh()->status === 'checking') {
            $service->checkSegment($second);
        }
        $service->confirm($admin, $first, $service->credential($first), $service->phrase($first));
        $this->assertTrue($service->isCurrent($second));
        $service->confirm($admin, $second, $service->credential($second), $service->phrase($second));
        $this->assertNotNull($second->refresh()->applied_at);
    }

    public function test_rule_revision_change_invalidates_checked_report(): void
    {
        $admin = $this->admin();
        $service = app(UrlChangeService::class);
        $service->start($admin, 'primary', null, '/{slug}.html');
        $change = $this->checkedChange();
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode(ArticlePermalinkPolicy::defaults()->activate('/articles/{id}')->toArray())]);
        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $service->credential($change), 'confirmation' => $service->phrase($change)])->assertSessionHasErrors('confirmation');
        $this->assertSame('stale', $change->refresh()->status);
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'risk-'.uniqid(), 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
    }

    public function test_admin_prefix_change_invalidates_a_rule_that_was_previously_legal(): void
    {
        $admin = $this->admin();
        $service = app(UrlChangeService::class);
        $service->start($admin, 'primary', null, '/reserved-admin/{slug}');
        $change = $this->checkedChange();
        $credential = $service->credential($change);
        config(['geoflow.admin_base_path' => '/reserved-admin']);
        $this->assertFalse($service->isCurrent($change));
        $this->actingAs($admin, 'admin')->post(route('admin.url-changes.confirm', $change), ['credential' => $credential, 'confirmation' => $service->phrase($change)])->assertSessionHasErrors('confirmation');
        $this->assertNull($change->refresh()->applied_at);
    }

    public function test_new_article_id_cannot_take_over_an_existing_category_path(): void
    {
        $category = Category::query()->create(['name' => 'Numeric', 'slug' => '2']);
        $other = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        Article::query()->create(['id' => 1, 'title' => 'Alpha', 'slug' => 'alpha', 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published']);
        $policy = ArticlePermalinkPolicy::defaults()->activate('/{id}/{slug}')->activate('/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        $this->expectException(ValidationException::class);
        DB::transaction(function () use ($other, $author): void {
            $article = Article::query()->create(['title' => 'Beta', 'slug' => 'beta', 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id, 'status' => 'published']);
            app(UrlChangeInspector::class)->assertArticleCompatible($article);
        });
    }

    public function test_noninteractive_protected_changes_are_atomic_and_require_admin_confirmation(): void
    {
        $category = Category::query()->create(['name' => 'Original', 'slug' => 'original']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        $article = Article::query()->create(['title' => 'Original', 'slug' => 'original-article', 'content' => 'Original body', 'category_id' => $category->id, 'author_id' => $author->id]);
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode(ArticlePermalinkPolicy::defaults()->activate('/{category}/{slug}')->toArray())]);
        foreach (['admin' => 403, 'super_admin' => 409] as $role => $status) {
            $admin = $this->admin();
            $admin->update(['role' => $role]);
            $token = $admin->createToken('url-guard', ['materials:write', 'articles:write'])->plainTextToken;
            app('auth')->forgetGuards();
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->patchJson('/api/v1/materials/categories/'.$category->id, ['name' => 'Attempted', 'slug' => 'new-url', 'force' => true, 'confirmed' => true])
                ->assertStatus($status);
            foreach ([['slug' => 'changed'], ['slug' => null], ['category_id' => $other->id], ['created_at' => '2020-01-01']] as $protected) {
                $this->patchJson('/api/v1/articles/'.$article->id, $protected + ['content' => 'Attempted body'])->assertStatus($status);
                $this->assertSame('Original body', $article->refresh()->content);
            }
            $this->assertSame('Original', $category->refresh()->name);
        }
        $this->patchJson('/api/v1/materials/categories/'.$category->id, ['name' => 'New display name'])->assertOk();
        $this->assertSame('original', $category->refresh()->slug);
        $this->postJson('/api/v1/materials/categories', ['name' => 'Reserved', 'slug' => 'admin'])->assertUnprocessable();
    }

    public function test_historical_category_paths_are_checked_against_new_rules(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        Article::query()->create(['id' => 1, 'title' => 'Alpha', 'slug' => 'alpha', 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id]);
        Article::query()->create(['id' => 2, 'title' => 'Beta', 'slug' => 'beta', 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id]);
        CategorySlugHistory::query()->create(['slug' => '2', 'category_id' => $category->id, 'original_category_id' => $category->id]);
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode(ArticlePermalinkPolicy::defaults()->activate('/{category}/{slug}')->toArray())]);
        $service = app(UrlChangeService::class);
        $change = $service->start($this->admin(), 'primary', null, '/{id}/{slug}');
        for ($step = 0; $step < 30 && $change->refresh()->status === 'checking'; $step++) {
            $service->checkSegment($change);
        }
        $this->assertSame('failed', $change->refresh()->status);
        $this->assertStringContainsString('#1', $change->error);
        $this->assertStringContainsString('#2', $change->error);
        $this->assertNull($change->nonce_hash);
    }

    public function test_archiving_hosted_site_after_apply_releases_scope_and_reports_skipped_refresh(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create(['name' => 'Archive', 'domain' => 'archive.sites.test', 'endpoint_url' => 'https://archive.sites.test', 'channel_type' => 'hosted_site', 'status' => 'active']);
        $profile = HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'archive.sites.test', 'root_domain' => 'sites.test', 'topic' => 'News', 'serving_status' => 'online']);
        $service = app(UrlChangeService::class);
        $service->start($admin, 'hosted', $channel->id, '/{slug}.html');
        $change = $this->checkedChange();
        $credential = $service->credential($change);
        $service->confirm($admin, $change, $credential, $service->phrase($change));
        $profile->update(['serving_status' => 'archived']);
        for ($step = 0; $step < 3; $step++) {
            app()->call([new RefreshUrlChange($change->id), 'handle']);
        }
        $this->assertSame('completed', $change->refresh()->status);
        $this->assertArrayHasKey('hosted:'.$channel->id, $change->summary['refresh_skipped']);
        $this->assertNull(DB::table('url_change_scope_states')->where('scope_key', 'hosted:'.$channel->id)->value('active_request_id'));
        $this->assertSame('completed', $service->confirm($admin, $change, $credential, $service->phrase($change))->status);
        $this->assertSame(1, ArticlePermalinkPolicy::fromRaw($channel->refresh()->site_settings[ArticlePermalinkPolicy::SETTING_KEY])->revision);
    }

    #[DataProvider('reverseOwnershipCases')]
    public function test_new_articles_cannot_steal_typed_or_escaped_historical_addresses(string $pattern, string $categorySlug, string $existingSlug, string $newSlug): void
    {
        $category = Category::query()->create(['name' => 'Existing', 'slug' => $categorySlug]);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        Article::query()->create(['id' => 1, 'title' => 'Existing', 'slug' => $existingSlug, 'content' => 'A', 'category_id' => $category->id, 'author_id' => $author->id]);
        $policy = ArticlePermalinkPolicy::defaults()->activate($pattern)->activate('/{category}/{slug}');
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode($policy->toArray())]);
        try {
            DB::transaction(function () use ($other, $author, $newSlug): void {
                $article = Article::query()->create(['id' => 2, 'title' => 'Attempt', 'slug' => $newSlug, 'content' => 'B', 'category_id' => $other->id, 'author_id' => $author->id]);
                app(UrlChangeInspector::class)->assertArticleCompatible($article);
            });
            $this->fail('The competing article URL must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }
        $this->assertDatabaseMissing('articles', ['id' => 2]);
    }

    public static function reverseOwnershipCases(): array
    {
        return [
            'typed id before hyphenated slug' => ['/fixed/{id}-{slug}', 'news', 'foo-bar', '1-foo-bar'],
            'underscore is a literal in SQLite and PostgreSQL' => ['/fixed/{id}_{slug}', 'fixed', '2_alpha', 'beta'],
            'literal percent encoding' => ['/fixed/{id}_{slug}', 'fixed', '2_中文', 'beta'],
        ];
    }
}
