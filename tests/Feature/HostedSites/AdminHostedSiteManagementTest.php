<?php

namespace Tests\Feature\HostedSites;

use App\Contracts\Outbound\OutboundTransport;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HostedSiteProbeTransport;
use Tests\TestCase;

class AdminHostedSiteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_super_admin_creates_a_safe_paused_hosted_site_without_a_secret(): void
    {
        foreach ([
            'copyright_info' => 'Primary brand copyright',
            'site_logo' => '/storage/primary-logo.png',
            'site_favicon' => '/storage/primary-favicon.ico',
            'filing_info' => 'Primary filing',
            'home_carousel_slides' => '[{"title":"Primary campaign"}]',
            'article_detail_ads' => '[{"name":"Primary ad"}]',
        ] as $key => $value) {
            SiteSetting::query()->create(['setting_key' => $key, 'setting_value' => $value]);
        }
        Cache::flush();

        $response = $this->actingAs($this->admin(), 'admin')->post(
            route('admin.distribution.hosted-sites.store'),
            [
                'name' => 'Alpha site',
                'hostname' => 'Alpha.Sites.Test',
                'topic' => '人工智能',
                'locale' => 'zh_CN',
                'timezone' => 'Asia/Shanghai',
                'daily_publish_limit' => 3,
                'min_publish_interval_minutes' => 0,
                'min_articles_before_index' => 1,
                'template_key' => 'default',
                'site_description' => 'Alpha description',
                'lead_form_slugs' => [''],
                'custom_html' => '<script>alert(1)</script>',
            ]
        );

        $channel = DistributionChannel::query()->where('channel_type', DistributionChannel::TYPE_HOSTED_SITE)->firstOrFail();
        $response->assertRedirect(route('admin.distribution.hosted-sites.show', $channel));
        $this->assertSame('alpha.sites.test', $channel->domain);
        $this->assertSame('https://alpha.sites.test', $channel->endpoint_url);
        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->status);
        $this->assertSame(0, $channel->secrets()->count());
        $this->assertArrayNotHasKey('custom_html', $channel->site_settings ?? []);
        foreach (['copyright_info', 'site_logo', 'site_favicon', 'filing_info', 'home_carousel_slides', 'article_detail_ads'] as $key) {
            $this->assertArrayNotHasKey($key, $channel->site_settings ?? []);
        }
        $this->assertDatabaseHas('hosted_site_profiles', [
            'distribution_channel_id' => $channel->id,
            'hostname' => 'alpha.sites.test',
            'serving_status' => HostedSiteProfile::SERVING_MAINTENANCE,
            'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PENDING,
        ]);
        $createAudit = AdminActivityLog::query()
            ->where('action', 'admin.distribution.hosted-sites.store:submit')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('hostedSite', $createAudit->target_type);
        $this->assertSame($channel->id, $createAudit->target_id);
        $this->assertStringContainsString('"success":true', (string) $createAudit->details);
        $this->assertStringContainsString('"after"', (string) $createAudit->details);
    }

    public function test_hostname_validation_rejects_reserved_nested_and_unconfigured_domains(): void
    {
        foreach (['admin.sites.test', 'deep.alpha.sites.test', 'alpha.other.test'] as $hostname) {
            $this->actingAs($this->admin(), 'admin')
                ->from(route('admin.distribution.hosted-sites.create'))
                ->post(route('admin.distribution.hosted-sites.store'), [
                    'name' => 'Invalid site',
                    'hostname' => $hostname,
                    'topic' => 'AI',
                    'locale' => 'zh_CN',
                    'timezone' => 'Asia/Shanghai',
                    'daily_publish_limit' => 3,
                    'min_publish_interval_minutes' => 0,
                    'min_articles_before_index' => 1,
                ])
                ->assertRedirect(route('admin.distribution.hosted-sites.create'))
                ->assertSessionHasErrors('hostname');
        }
    }

    public function test_hosted_site_routes_require_super_admin_and_channel_type_scope(): void
    {
        $regular = $this->admin('admin');
        $this->actingAs($regular, 'admin')
            ->get(route('admin.distribution.hosted-sites.index'))
            ->assertForbidden();

        $external = DistributionChannel::query()->create([
            'name' => 'External',
            'domain' => 'external.test',
            'endpoint_url' => 'https://external.test',
            'channel_type' => 'geoflow_agent',
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.hosted-sites.show', $external))
            ->assertNotFound();
    }

    public function test_preflight_activation_pause_maintenance_and_archive_are_explicit_actions(): void
    {
        $admin = $this->admin();
        $channel = $this->hostedChannel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.preflight', $channel))
            ->assertRedirect();
        $this->assertSame('ok', $channel->fresh()->last_health_status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.activate', $channel))
            ->assertRedirect();
        $this->assertSame(DistributionChannel::STATUS_ACTIVE, $channel->fresh()->status);
        $this->assertSame(HostedSiteProfile::SERVING_ONLINE, $channel->hostedSiteProfile->fresh()->serving_status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.pause', $channel))
            ->assertRedirect();
        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->fresh()->status);
        $this->assertSame(HostedSiteProfile::SERVING_ONLINE, $channel->hostedSiteProfile->fresh()->serving_status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.maintenance', $channel))
            ->assertRedirect();
        $this->assertSame(HostedSiteProfile::SERVING_MAINTENANCE, $channel->hostedSiteProfile->fresh()->serving_status);
        $this->assertSame(HostedSiteProfile::INDEXING_NOINDEX, $channel->hostedSiteProfile->fresh()->indexing_status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.archive', $channel), ['hostname' => 'alpha.sites.test'])
            ->assertRedirect(route('admin.distribution.hosted-sites.index'));
        $this->assertSame(HostedSiteProfile::SERVING_ARCHIVED, $channel->hostedSiteProfile->fresh()->serving_status);
    }

    public function test_manual_assignment_cannot_send_an_article_to_a_different_hosted_site(): void
    {
        $alpha = $this->hostedChannel();
        $beta = $this->hostedChannel('beta');
        foreach ([$alpha, $beta] as $channel) {
            $channel->update(['status' => DistributionChannel::STATUS_ACTIVE]);
            $channel->hostedSiteProfile->update([
                'serving_status' => HostedSiteProfile::SERVING_ONLINE,
                'quality_status' => HostedSiteProfile::QUALITY_PASSED,
            ]);
        }
        $task = Task::query()->create([
            'name' => 'Beta hosted task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($beta->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);
        $category = Category::query()->create(['name' => 'AI', 'slug' => 'ai', 'sort_order' => 0]);
        $author = Author::query()->create([
            'name' => 'Hosted author',
            'email' => 'hosted-admin@example.test',
            'bio' => '',
            'avatar' => '',
            'website' => '',
        ]);
        $article = Article::query()->create([
            'title' => 'Beta article',
            'slug' => 'beta-article',
            'content' => 'Beta content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.distribution.hosted-sites.show', $alpha))
            ->post(route('admin.distribution.hosted-sites.articles.assign', $alpha), [
                'article_id' => $article->id,
            ])
            ->assertRedirect(route('admin.distribution.hosted-sites.show', $alpha))
            ->assertSessionHasErrors('article_id');

        $this->assertDatabaseCount('hosted_site_article_assignments', 0);
        $this->assertDatabaseCount('article_distributions', 0);
        $this->assertDatabaseCount('hosted_site_allocation_requests', 0);
    }

    public function test_online_edit_returns_the_site_to_safe_maintenance_until_rechecked(): void
    {
        $channel = $this->hostedChannel();
        $channel->update(['status' => DistributionChannel::STATUS_ACTIVE]);
        $channel->hostedSiteProfile->update([
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.distribution.hosted-sites.update', $channel), [
                'name' => 'Alpha renamed',
                'hostname' => 'alpha.sites.test',
                'topic' => 'AI operations',
                'locale' => 'zh_CN',
                'timezone' => 'Asia/Shanghai',
                'daily_publish_limit' => 4,
                'publish_weight' => 100,
                'min_publish_interval_minutes' => 0,
                'min_articles_before_index' => 1,
                'template_key' => 'default',
                'site_description' => 'Updated description',
                'about_title' => 'About Alpha',
                'about_content' => 'Updated Alpha information.',
                'lead_form_slugs' => [''],
            ])
            ->assertRedirect(route('admin.distribution.hosted-sites.show', $channel));

        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->fresh()->status);
        $this->assertSame(HostedSiteProfile::SERVING_MAINTENANCE, $channel->hostedSiteProfile->fresh()->serving_status);
        $this->assertSame(HostedSiteProfile::QUALITY_PENDING, $channel->hostedSiteProfile->fresh()->quality_status);
    }

    public function test_preflight_reports_incomplete_identity_and_non_certified_theme(): void
    {
        $channel = $this->hostedChannel();
        $channel->update([
            'template_key' => 'geoflow-template-21-enterprise-signature',
            'site_settings' => ['site_name' => 'Alpha site', 'site_description' => 'Description'],
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.hosted-sites.preflight', $channel))
            ->assertSessionHasErrors();
        $messages = implode(' ', $response->getSession()->get('errors')->all());
        $this->assertStringContainsString('about', $messages);
        $this->assertStringContainsString('theme', $messages);
        $this->assertStringContainsString('about', (string) $channel->fresh()->last_error_message);
    }

    public function test_activation_requires_a_recent_preflight_and_lifecycle_audit_has_target_and_transition(): void
    {
        $admin = $this->admin();
        $channel = $this->hostedChannel();
        $channel->update([
            'last_health_status' => 'ok',
            'last_health_checked_at' => now()->subHour(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.activate', $channel))
            ->assertSessionHasErrors();
        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->fresh()->status);
        $failedAudit = AdminActivityLog::query()
            ->where('action', 'admin.distribution.hosted-sites.activate:submit')
            ->latest('id')
            ->firstOrFail();
        $this->assertStringContainsString('"success":false', (string) $failedAudit->details);
        $this->assertStringContainsString('"error"', (string) $failedAudit->details);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.preflight', $channel))
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.activate', $channel))
            ->assertSessionDoesntHaveErrors();

        $audit = AdminActivityLog::query()
            ->where('action', 'admin.distribution.hosted-sites.activate:submit')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('hostedSite', $audit->target_type);
        $this->assertSame($channel->id, $audit->target_id);
        $this->assertStringContainsString('before', (string) $audit->details);
        $this->assertStringContainsString('after', (string) $audit->details);
    }

    public function test_failed_online_probe_returns_activation_to_safe_maintenance(): void
    {
        $channel = $this->hostedChannel();
        $channel->update([
            'last_health_status' => 'ok',
            'last_health_checked_at' => now(),
        ]);
        $channel->hostedSiteProfile->update(['quality_status' => HostedSiteProfile::QUALITY_PASSED]);
        $transport = new HostedSiteProbeTransport;
        $transport->healthy = false;
        $this->app->instance(OutboundTransport::class, $transport);
        config()->set('geoflow.hosted_sites.network_preflight_enabled', true);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.hosted-sites.activate', $channel))
            ->assertSessionHasErrors();

        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->fresh()->status);
        $this->assertSame(
            HostedSiteProfile::SERVING_MAINTENANCE,
            $channel->hostedSiteProfile->fresh()->serving_status
        );
        $this->assertSame(
            HostedSiteProfile::INDEXING_NOINDEX,
            $channel->hostedSiteProfile->fresh()->indexing_status
        );
        $this->assertSame(
            HostedSiteProfile::QUALITY_BLOCKED,
            $channel->hostedSiteProfile->fresh()->quality_status
        );
        $this->assertNull(data_get($channel->fresh()->channel_config, 'hosted_site_activation_token'));
    }

    public function test_indexing_action_requires_confirmation_visible_content_and_current_online_preflight(): void
    {
        $admin = $this->admin();
        $channel = $this->hostedChannel();
        $task = Task::query()->create([
            'name' => 'Indexing task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);
        $category = Category::query()->create(['name' => 'Indexing', 'slug' => 'indexing', 'sort_order' => 0]);
        $author = Author::query()->create([
            'name' => 'Indexing author',
            'email' => 'indexing@example.test',
            'bio' => '',
            'avatar' => '',
            'website' => '',
        ]);
        $article = Article::query()->create([
            'title' => 'Indexable hosted article',
            'slug' => 'indexable-hosted-article',
            'content' => 'Indexable content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id,
            'hosted_site_profile_id' => $channel->hostedSiteProfile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', 'indexable-hosted-article'),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.preflight', $channel))
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.activate', $channel))
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.indexing', $channel), [
                'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            ])
            ->assertSessionHasErrors('quality_confirmed');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.indexing', $channel), [
                'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
                'quality_confirmed' => '1',
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            HostedSiteProfile::INDEXING_INDEX,
            $channel->hostedSiteProfile->fresh()->indexing_status
        );
    }

    public function test_detail_page_exposes_phase_one_operational_state(): void
    {
        $channel = $this->hostedChannel();
        $channel->update(['last_error_message' => 'Latest safe diagnostic']);
        $task = Task::query()->create([
            'name' => 'Bound operations task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.hosted-sites.show', $channel))
            ->assertOk()
            ->assertSee('今日容量')
            ->assertSee('最近健康检查')
            ->assertSee('连续发布失败')
            ->assertSee('访问归属')
            ->assertSee('线索归属')
            ->assertSee('Bound operations task')
            ->assertSee('Latest safe diagnostic')
            ->assertSee('最近分配请求');
    }

    public function test_generic_distribution_actions_cannot_bypass_hosted_lifecycle(): void
    {
        $channel = $this->hostedChannel();
        $admin = $this->admin();
        $target = route('admin.distribution.hosted-sites.show', $channel);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => $channel->id]))
            ->assertRedirect($target);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => $channel->id]))
            ->assertRedirect($target);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.health', ['channelId' => $channel->id]))
            ->assertRedirect($target);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.activate', ['channelId' => $channel->id]))
            ->assertRedirect($target);

        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->fresh()->status);
        $this->assertNull($channel->fresh()->last_health_status);
    }

    private function hostedChannel(string $label = 'alpha'): DistributionChannel
    {
        $channel = DistributionChannel::query()->create([
            'name' => ucfirst($label).' site',
            'domain' => $label.'.sites.test',
            'endpoint_url' => 'https://'.$label.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_PAUSED,
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Alpha site',
                'site_description' => 'Description',
                'about_title' => 'About Alpha',
                'about_content' => 'Alpha site information.',
            ],
        ]);
        HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => $label.'.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'min_publish_interval_minutes' => 0,
            'min_articles_before_index' => 1,
        ]);

        return $channel->fresh('hostedSiteProfile');
    }

    private function admin(string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $role.'-'.uniqid(),
            'password' => 'Password123!',
            'email' => uniqid().'@example.test',
            'display_name' => 'Hosted operator',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
