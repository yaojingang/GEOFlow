<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Prompt;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Services\GeoFlow\DistributionSigningService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class AdminDistributionPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_distribution_management_page(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.page_heading'));
    }

    public function test_distribution_index_recent_logs_are_paginated_with_jump_input(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '分页渠道',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            DistributionLog::query()->create([
                'distribution_channel_id' => (int) $channel->id,
                'level' => 'info',
                'event' => 'distribution.test',
                'message' => sprintf('paged-log-%02d', $i),
                'created_at' => now()->subMinutes(12 - $i),
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.index'))
            ->assertOk()
            ->assertSee('paged-log-12')
            ->assertSee('paged-log-03')
            ->assertDontSee('paged-log-02')
            ->assertDontSee('paged-log-01')
            ->assertSee(__('admin.distribution.pagination.pages', ['page' => 1, 'total_pages' => 2]))
            ->assertSee('name="logs_page"', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.index', ['logs_page' => 2]))
            ->assertOk()
            ->assertSee('paged-log-02')
            ->assertSee('paged-log-01')
            ->assertDontSee('paged-log-12')
            ->assertSee(__('admin.distribution.pagination.pages', ['page' => 2, 'total_pages' => 2]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.index', ['logs_page' => 999]))
            ->assertOk()
            ->assertSee('paged-log-02')
            ->assertSee(__('admin.distribution.pagination.pages', ['page' => 2, 'total_pages' => 2]));
    }

    public function test_distribution_pages_do_not_render_missing_translation_keys(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.index'))
            ->assertOk()
            ->assertSee('渠道总数')
            ->assertSee('活跃渠道')
            ->assertSee('待处理分发')
            ->assertSee('失败分发')
            ->assertDontSee('admin.distribution.stats.')
            ->assertDontSee('admin.button.reset');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.jobs'))
            ->assertOk()
            ->assertSee('重置')
            ->assertDontSee('admin.distribution.')
            ->assertDontSee('admin.button.reset');
    }

    public function test_distribution_pages_localize_internal_enum_values(): void
    {
        $admin = $this->admin();
        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionLog::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'level' => 'info',
            'event' => 'distribution.queued',
            'message' => '已入队',
            'created_at' => now(),
        ]);
        $article = Article::query()->create([
            'title' => '枚举本地化文章',
            'slug' => 'localized-enum-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'localized-enum-job',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.status.active'))
            ->assertSee(__('admin.distribution.log_level.info'))
            ->assertDontSee('>active</span>', false)
            ->assertDontSee('· info</div>', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.status.active'))
            ->assertSee(__('admin.distribution.action.publish'))
            ->assertSee(__('admin.distribution.log_level.info'))
            ->assertSee('枚举本地化文章')
            ->assertDontSee('>active</dd>', false)
            ->assertDontSee('>publish</td>', false)
            ->assertDontSee('info ·', false);
    }

    public function test_admin_can_create_distribution_channel_and_see_secret_once(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '官网主站',
                'domain' => 'https://example.com',
                'endpoint_url' => 'https://example.com',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '主站 Agent',
            ])
            ->assertRedirect(route('admin.distribution.index'))
            ->assertSessionHas('distribution_secret');

        $channel = DistributionChannel::query()->where('name', '官网主站')->firstOrFail();
        $secret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->firstOrFail();

        $this->assertSame('example.com', $channel->domain);
        $this->assertSame('static', (string) $channel->front_mode);
        $this->assertNotSame(session('distribution_secret.secret'), $secret->secret_ciphertext);
        $this->assertStringStartsWith('gfk_', (string) $secret->key_id);
    }

    public function test_admin_can_create_distribution_channel_with_host_only_endpoint(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '猎河主站',
                'domain' => 'www.liehe.com',
                'endpoint_url' => 'www.liehe.com',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '主站 Agent',
            ])
            ->assertRedirect(route('admin.distribution.index'))
            ->assertSessionHas('distribution_secret.endpoint_url', 'https://www.liehe.com');

        $this->assertDatabaseHas('distribution_channels', [
            'name' => '猎河主站',
            'domain' => 'www.liehe.com',
            'endpoint_url' => 'https://www.liehe.com',
        ]);
    }

    public function test_distribution_channel_form_accepts_plain_host_agent_address(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.create'))
            ->assertOk()
            ->assertSee('name="endpoint_url" type="text"', false)
            ->assertDontSee('name="endpoint_url" type="url"', false)
            ->assertSee('静态文件模式')
            ->assertSee('伪静态模式')
            ->assertSee(__('admin.distribution.help.endpoint_url'));
    }

    public function test_distribution_channel_create_form_collapses_template_choices_after_two_rows(): void
    {
        $this->app->instance(SiteThemeCatalog::class, new class extends SiteThemeCatalog
        {
            public function all(): array
            {
                return collect(range(1, 8))
                    ->map(fn (int $index): array => [
                        'id' => sprintf('theme-%02d', $index),
                        'name' => sprintf('Theme %02d', $index),
                        'version' => '1.0.0',
                        'description' => sprintf('Theme %02d description', $index),
                    ])
                    ->all();
            }
        });

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.create'))
            ->assertOk()
            ->assertSee(__('admin.site_settings.theme.section_title'))
            ->assertSee('name="template_key" value=""', false)
            ->assertSee('name="template_key" value="theme-01"', false)
            ->assertSee(__('admin.distribution.remote_site.template_expand_more', ['count' => 3]));

        $html = (string) $response->getContent();

        $this->assertSame(3, substr_count($html, 'data-distribution-theme-collapsed="true"'));
        $this->assertMatchesRegularExpression('/class="[^"]*hidden[^"]*"[^>]*data-distribution-theme-card[^>]*data-distribution-theme-collapsed="true"[^>]*>\\s*<input[^>]+value="theme-06"/s', $html);
    }

    public function test_wordpress_distribution_channel_form_shows_wordpress_fields(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.create'))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.wordpress_rest'))
            ->assertSee(__('admin.distribution.wordpress.section_title'))
            ->assertSee('name="wordpress_username"', false)
            ->assertSee('name="wordpress_application_password"', false)
            ->assertSee('name="wordpress_post_status"', false)
            ->assertSee('name="wordpress_category_strategy"', false)
            ->assertSee('name="wordpress_tag_strategy"', false)
            ->assertSee('name="wordpress_image_strategy"', false);
    }

    public function test_admin_can_create_wordpress_distribution_channel(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => 'WordPress 站点',
                'domain' => 'wp.example.com',
                'endpoint_url' => 'https://wp.example.com',
                'channel_type' => 'wordpress_rest',
                'wordpress_username' => 'editor',
                'wordpress_application_password' => 'app password',
                'wordpress_post_status' => 'draft',
                'wordpress_category_strategy' => 'match_or_create',
                'wordpress_fixed_category' => '',
                'wordpress_tag_strategy' => 'keywords_to_tags',
                'wordpress_image_strategy' => 'upload_to_media',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.distribution.index'))
            ->assertSessionMissing('distribution_secret');

        $this->assertDatabaseHas('distribution_channels', [
            'name' => 'WordPress 站点',
            'channel_type' => 'wordpress_rest',
            'endpoint_url' => 'https://wp.example.com',
        ]);

        $channel = DistributionChannel::query()->where('name', 'WordPress 站点')->firstOrFail();
        $this->assertSame('editor', $channel->resolvedChannelConfig()['wordpress_username']);
        $this->assertSame('draft', $channel->resolvedChannelConfig()['wordpress_post_status']);
        $this->assertSame('upload_to_media', $channel->resolvedChannelConfig()['wordpress_image_strategy']);

        $secret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->firstOrFail();
        $this->assertStringStartsWith('wp_', (string) $secret->key_id);
        $this->assertSame(['wordpress.rest'], $secret->scopes);
        $this->assertSame('app password', app(ApiKeyCrypto::class)->decrypt((string) $secret->secret_ciphertext));
    }

    public function test_wordpress_distribution_channel_requires_application_password_on_create(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => 'WordPress 站点',
                'domain' => 'wp.example.com',
                'endpoint_url' => 'https://wp.example.com',
                'channel_type' => 'wordpress_rest',
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'draft',
                'wordpress_category_strategy' => 'match_or_create',
                'wordpress_tag_strategy' => 'keywords_to_tags',
                'wordpress_image_strategy' => 'upload_to_media',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('wordpress_application_password');
    }

    public function test_generic_api_distribution_channel_form_shows_generic_fields(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.create'))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.generic_http_api'))
            ->assertSee(__('admin.distribution.generic.section_title'))
            ->assertSee('name="generic_auth_type"', false)
            ->assertSee('name="generic_secret"', false)
            ->assertSee('name="generic_publish_path"', false)
            ->assertSee('name="generic_remote_id_path"', false);
    }

    public function test_admin_can_create_generic_api_distribution_channel(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '通用 API',
                'domain' => 'api.example.com',
                'endpoint_url' => 'https://api.example.com',
                'channel_type' => 'generic_http_api',
                'generic_auth_type' => 'bearer',
                'generic_secret' => 'api-token',
                'generic_success_statuses' => '200,201,202',
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'generic_settings_method' => 'POST',
                'generic_settings_path' => '/site-settings',
                'generic_remote_id_path' => 'data.id',
                'generic_remote_url_path' => 'data.url',
                'generic_payload_wrapper' => 'none',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionMissing('distribution_secret');

        $channel = DistributionChannel::query()->where('name', '通用 API')->firstOrFail();
        $this->assertSame('generic_http_api', $channel->channelType());
        $this->assertSame('data.id', $channel->resolvedGenericHttpConfig()['generic_remote_id_path']);
        $this->assertSame([200, 201, 202], $channel->resolvedGenericHttpConfig()['generic_success_statuses']);

        $secret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->firstOrFail();
        $this->assertStringStartsWith('gapi_', (string) $secret->key_id);
        $this->assertSame(['generic.http'], $secret->scopes);
        $this->assertSame('api-token', app(ApiKeyCrypto::class)->decrypt((string) $secret->secret_ciphertext));
    }

    public function test_admin_can_create_generic_api_distribution_channel_without_auth(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '公开 API',
                'domain' => 'api.example.com',
                'endpoint_url' => 'https://api.example.com',
                'channel_type' => 'generic_http_api',
                'generic_auth_type' => 'none',
                'generic_success_statuses' => '200,204',
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{slug}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{slug}',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionMissing('distribution_secret');

        $channel = DistributionChannel::query()->where('name', '公开 API')->firstOrFail();
        $this->assertSame('generic_http_api', $channel->channelType());
        $this->assertSame('none', $channel->resolvedGenericHttpConfig()['generic_auth_type']);
        $this->assertSame('', $channel->resolvedGenericHttpConfig()['generic_settings_path']);
        $this->assertFalse(DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->exists());
    }

    public function test_generic_api_distribution_rejects_methods_that_cannot_send_article_payloads(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '错误 API',
                'domain' => 'api.example.com',
                'endpoint_url' => 'https://api.example.com',
                'channel_type' => 'generic_http_api',
                'generic_auth_type' => 'bearer',
                'generic_secret' => 'api-token',
                'generic_success_statuses' => '200,201',
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'GET',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('generic_publish_method');
    }

    public function test_generic_api_distribution_rejects_invalid_header_names(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => 'Header API',
                'domain' => 'api.example.com',
                'endpoint_url' => 'https://api.example.com',
                'channel_type' => 'generic_http_api',
                'generic_auth_type' => 'header_key',
                'generic_secret' => 'api-token',
                'generic_header_name' => 'Bad Header',
                'generic_success_statuses' => '200,201',
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('generic_header_name');
    }

    public function test_generic_api_distribution_rejects_blank_required_paths(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '路径错误 API',
                'domain' => 'api.example.com',
                'endpoint_url' => 'https://api.example.com',
                'channel_type' => 'generic_http_api',
                'generic_auth_type' => 'bearer',
                'generic_secret' => 'api-token',
                'generic_success_statuses' => '200,201',
                'generic_health_method' => 'GET',
                'generic_health_path' => '',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('generic_health_path');
    }

    public function test_generic_api_distribution_rejects_full_url_paths(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), [
                'name' => '完整 URL API',
                'domain' => 'api.example.com',
                'endpoint_url' => 'https://api.example.com',
                'channel_type' => 'generic_http_api',
                'generic_auth_type' => 'bearer',
                'generic_secret' => 'api-token',
                'generic_success_statuses' => '200,201',
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => 'https://api.example.com/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('generic_publish_path');
    }

    public function test_generic_api_distribution_detail_hides_target_site_package_and_shows_generic_guide(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '通用 API',
            'domain' => 'api.example.com',
            'endpoint_url' => 'https://api.example.com',
            'channel_type' => 'generic_http_api',
            'channel_config' => [
                'generic_auth_type' => 'none',
                'generic_health_path' => '/channels/{channel_id}/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
            ],
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.generic_http_api'))
            ->assertSee(__('admin.distribution.generic.guide_title'))
            ->assertSee(__('admin.distribution.generic.sample_payload_title'))
            ->assertSee(__('admin.distribution.generic.response_mapping_title'))
            ->assertSee('/channels/'.(int) $channel->id.'/health')
            ->assertDontSee(__('admin.distribution.detail.target_package_files'))
            ->assertDontSee(__('admin.distribution.wordpress.guide_title'))
            ->assertDontSee(__('admin.distribution.button.download_package'))
            ->assertDontSee(__('admin.distribution.rewrite.copy_nginx'))
            ->assertDontSee(__('admin.distribution.button.rotate_secret'));
    }

    public function test_generic_api_distribution_edit_shows_generic_settings_without_agent_rewrite_controls(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '通用 API',
            'domain' => 'api.example.com',
            'endpoint_url' => 'https://api.example.com',
            'channel_type' => 'generic_http_api',
            'channel_config' => [
                'generic_auth_type' => 'bearer',
                'generic_publish_path' => '/articles',
            ],
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.generic_http_api'))
            ->assertSee(__('admin.distribution.generic.section_title'))
            ->assertSee('name="channel_type" value="generic_http_api"', false)
            ->assertSee('name="generic_auth_type"', false)
            ->assertSee('name="generic_publish_path"', false)
            ->assertDontSee(__('admin.distribution.front_mode.static'))
            ->assertDontSee(__('admin.distribution.rewrite.title'))
            ->assertDontSee(__('admin.site_settings.theme.section_title'));
    }

    public function test_wordpress_distribution_detail_hides_target_site_package_and_shows_connection_guide(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WordPress 站点',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'draft',
                'wordpress_category_strategy' => 'match_or_create',
                'wordpress_tag_strategy' => 'keywords_to_tags',
                'wordpress_image_strategy' => 'upload_to_media',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_testsecret',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.wordpress_rest'))
            ->assertSee(__('admin.distribution.wordpress.guide_title'))
            ->assertSee('/wp-json/wp/v2/users/me?context=edit')
            ->assertSee(__('admin.distribution.wordpress.secret_hint'))
            ->assertDontSee(__('admin.distribution.detail.target_package_files'))
            ->assertDontSee(__('admin.distribution.detail.agent_package_name'))
            ->assertDontSee(__('admin.distribution.button.download_package'))
            ->assertDontSee(__('admin.distribution.rewrite.copy_nginx'))
            ->assertDontSee(__('admin.distribution.button.rotate_secret'));
    }

    public function test_wordpress_distribution_edit_shows_wordpress_settings_without_agent_rewrite_controls(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WordPress 站点',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'draft',
                'wordpress_category_strategy' => 'match_or_create',
                'wordpress_tag_strategy' => 'keywords_to_tags',
                'wordpress_image_strategy' => 'upload_to_media',
            ],
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.wordpress_rest'))
            ->assertSee(__('admin.distribution.help.channel_type_locked'))
            ->assertSee(__('admin.distribution.wordpress.section_title'))
            ->assertSee('name="channel_type" value="wordpress_rest"', false)
            ->assertSee('name="wordpress_username"', false)
            ->assertSee(__('admin.distribution.wordpress.application_password_update_help'))
            ->assertDontSee(__('admin.distribution.front_mode.static'))
            ->assertDontSee(__('admin.distribution.rewrite.title'))
            ->assertDontSee(__('admin.site_settings.theme.section_title'));
    }

    public function test_wordpress_distribution_health_check_updates_channel_status(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json' => Http::response(['name' => 'WordPress']),
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 7,
                'name' => 'Editor',
                'capabilities' => [
                    'edit_posts' => true,
                    'publish_posts' => true,
                    'upload_files' => true,
                ],
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => 'WordPress 站点',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'draft',
                'wordpress_category_strategy' => 'fixed',
                'wordpress_tag_strategy' => 'disabled',
                'wordpress_image_strategy' => 'keep_original',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_health',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]))
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'last_health_status' => 'ok',
            'last_error_message' => null,
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://wp.example.com/wp-json/wp/v2/users/me?context=edit');
    }

    public function test_admin_can_update_distribution_channel(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '旧渠道',
            'domain' => 'old.example.com',
            'endpoint_url' => 'https://old.example.com',
            'template_key' => 'old',
            'status' => 'active',
            'description' => '旧描述',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.edit_heading'))
            ->assertSee('旧渠道')
            ->assertSee('目标站点设置')
            ->assertSee('网站名称')
            ->assertSee('版权信息')
            ->assertSee('网站模板')
            ->assertSee('静态文件模式')
            ->assertSee('伪静态模式')
            ->assertSee('默认前台模板')
            ->assertSee('Toutiao News Inspired')
            ->assertSee('更新目标站点')
            ->assertSee('覆盖新版站点包后')
            ->assertSee(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]), false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '官网主站',
                'domain' => 'https://www.example.com/path',
                'endpoint_url' => 'https://www.example.com/',
                'front_mode' => 'rewrite',
                'template_key' => 'toutiao-news-20260426',
                'status' => 'paused',
                'description' => '更新后的主站 Agent',
                'site_name' => '目标门户',
                'site_subtitle' => '远程站点副标题',
                'site_description' => '远程站点描述',
                'site_keywords' => 'geo,ai,content',
                'copyright_info' => '© 2026 目标门户',
                'site_logo' => 'https://www.example.com/logo.png',
                'site_favicon' => 'https://www.example.com/favicon.ico',
                'seo_title_template' => '{title} - 目标门户',
                'seo_description_template' => '{description} - {site_name}',
                'featured_limit' => 8,
                'per_page' => 16,
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'name' => '官网主站',
            'domain' => 'www.example.com',
            'endpoint_url' => 'https://www.example.com',
            'front_mode' => 'rewrite',
            'template_key' => 'toutiao-news-20260426',
            'status' => 'paused',
            'description' => '更新后的主站 Agent',
        ]);

        $channel->refresh();
        $this->assertSame([
            'site_name' => '目标门户',
            'site_subtitle' => '远程站点副标题',
            'site_description' => '远程站点描述',
            'site_keywords' => 'geo,ai,content',
            'copyright_info' => '© 2026 目标门户',
            'site_logo' => 'https://www.example.com/logo.png',
            'site_favicon' => 'https://www.example.com/favicon.ico',
            'seo_title_template' => '{title} - 目标门户',
            'seo_description_template' => '{description} - {site_name}',
            'featured_limit' => 8,
            'per_page' => 16,
        ], $channel->site_settings);
    }

    public function test_distribution_channel_article_text_ad_policy_is_saved_and_reflected_in_payload(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_text_ads'],
            ['setting_value' => json_encode([
                [
                    'id' => 'sync-top',
                    'name' => 'Top Sync',
                    'placement' => 'content_top',
                    'text' => 'Top Sync CTA',
                    'url' => '/top-sync',
                    'text_color' => '#2563eb',
                    'open_new_tab' => false,
                    'tracking_enabled' => false,
                    'tracking_param' => '',
                    'enabled' => true,
                    'sort_order' => 10,
                ],
                [
                    'id' => 'sync-bottom',
                    'name' => 'Bottom Sync',
                    'placement' => 'content_bottom',
                    'text' => 'Bottom Sync CTA',
                    'url' => '/bottom-sync',
                    'text_color' => '#16a34a',
                    'open_new_tab' => true,
                    'tracking_enabled' => true,
                    'tracking_param' => 'utm_source=geoflow',
                    'enabled' => true,
                    'sort_order' => 20,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '文本广告渠道',
            'domain' => 'ads.example.com',
            'endpoint_url' => 'https://ads.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '文本广告渠道',
                'domain' => 'ads.example.com',
                'endpoint_url' => 'https://ads.example.com',
                'front_mode' => 'static',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '',
                'site_name' => '广告目标站',
                'site_subtitle' => '',
                'site_description' => '广告目标站描述',
                'site_keywords' => '',
                'copyright_info' => '© 2026 广告目标站',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'article_text_ad_policy' => [
                    'content_top' => [
                        'mode' => 'selected',
                        'module_ids' => ['sync-top'],
                    ],
                    'content_bottom' => [
                        'mode' => 'disabled',
                        'ad_ids' => ['sync-bottom'],
                    ],
                ],
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $channel->refresh();
        $policy = $channel->resolvedArticleTextAdPolicy();
        $this->assertSame('selected', $policy['content_top']['mode']);
        $this->assertSame(['sync-top'], $policy['content_top']['module_ids']);
        $this->assertSame('disabled', $policy['content_bottom']['mode']);

        $payload = $channel->targetSiteSettingsPayload();
        $this->assertArrayHasKey('article_text_ads', $payload);
        $this->assertCount(1, $payload['article_text_ads']);
        $this->assertSame('sync-top', $payload['article_text_ads'][0]['id']);
        $this->assertSame('Top Sync CTA', $payload['article_text_ads'][0]['links'][0]['text']);
    }

    public function test_distribution_channel_can_use_custom_article_text_ad_modules(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '自定义文本广告渠道',
            'domain' => 'custom-ads.example.com',
            'endpoint_url' => 'https://custom-ads.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '自定义文本广告渠道',
                'domain' => 'custom-ads.example.com',
                'endpoint_url' => 'https://custom-ads.example.com',
                'front_mode' => 'static',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '',
                'site_name' => '自定义广告站',
                'site_subtitle' => '',
                'site_description' => '自定义广告站描述',
                'site_keywords' => '',
                'copyright_info' => '© 2026 自定义广告站',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'article_text_ad_policy' => [
                    'content_top' => [
                        'mode' => 'custom',
                        'custom_modules' => [
                            [
                                'name' => '渠道顶部推荐',
                                'placement' => 'content_top',
                                'enabled' => '1',
                                'sort_order' => 10,
                                'links' => [
                                    [
                                        'text' => '渠道独立统计链接',
                                        'url' => 'https://custom.example.com/landing',
                                        'text_color' => '#dc2626',
                                        'open_new_tab' => '1',
                                        'tracking_enabled' => '1',
                                        'tracking_param' => 'utm_source=custom_channel',
                                        'enabled' => '1',
                                        'sort_order' => 10,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'content_bottom' => [
                        'mode' => 'disabled',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $channel->refresh();
        $policy = $channel->resolvedArticleTextAdPolicy();
        $this->assertSame('custom', $policy['content_top']['mode']);
        $this->assertCount(1, $policy['content_top']['custom_modules']);
        $this->assertSame('渠道顶部推荐', $policy['content_top']['custom_modules'][0]['name']);

        $payload = $channel->targetSiteSettingsPayload();
        $this->assertCount(1, $payload['article_text_ads']);
        $this->assertSame('渠道顶部推荐', $payload['article_text_ads'][0]['name']);
        $this->assertSame('渠道独立统计链接', $payload['article_text_ads'][0]['links'][0]['text']);
    }

    public function test_distribution_channel_rejects_invalid_custom_article_text_ad_modules(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '非法文本广告渠道',
            'domain' => 'invalid-ads.example.com',
            'endpoint_url' => 'https://invalid-ads.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '非法文本广告渠道',
                'domain' => 'invalid-ads.example.com',
                'endpoint_url' => 'https://invalid-ads.example.com',
                'front_mode' => 'static',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '',
                'site_name' => '非法广告站',
                'site_subtitle' => '',
                'site_description' => '非法广告站描述',
                'site_keywords' => '',
                'copyright_info' => '© 2026 非法广告站',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'article_text_ad_policy' => [
                    'content_top' => [
                        'mode' => 'custom',
                        'custom_modules' => [
                            [
                                'name' => '非法渠道模块',
                                'placement' => 'content_top',
                                'enabled' => '1',
                                'links' => [
                                    [
                                        'text' => '危险链接',
                                        'url' => 'javascript:alert(1)',
                                        'text_color' => '#2563eb',
                                        'enabled' => '1',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'content_bottom' => [
                        'mode' => 'disabled',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertSessionHasErrors('article_text_ad_policy');

        $channel->refresh();
        $this->assertSame([], $channel->channel_config ?? []);
    }

    public function test_admin_update_distribution_channel_syncs_remote_site_settings_when_secret_exists(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '旧远程门户',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_update_settings',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_update_settings_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '官网主站',
                'domain' => 'example.com',
                'endpoint_url' => 'https://example.com',
                'front_mode' => 'static',
                'template_key' => 'netease-news-20260507',
                'status' => 'active',
                'description' => '自动同步远端设置',
                'site_name' => '更新后的远程门户',
                'site_subtitle' => '远程副标题',
                'site_description' => '远程站点描述',
                'site_keywords' => 'geo,ai,remote',
                'copyright_info' => '© 2026 更新后的远程门户',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - 更新后的远程门户',
                'seo_description_template' => '{description} - {site_name}',
                'featured_limit' => 6,
                'per_page' => 12,
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('message', __('admin.distribution.message.updated_and_settings_synced'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update')
            && $request['settings']['site_name'] === '更新后的远程门户'
            && $request['settings']['active_theme'] === 'netease-news-20260507'
            && $request['settings']['front_mode'] === 'static'
            && $request['settings']['per_page'] === 12);

        $this->assertDatabaseHas('distribution_logs', [
            'distribution_channel_id' => (int) $channel->id,
            'event' => 'site.settings.synced',
        ]);
    }

    public function test_site_settings_sync_falls_back_to_index_php_entry_when_rewrite_is_missing(): void
    {
        Http::fake([
            'https://example.com/geoflow/geoflow-agent/v1/site-settings' => Http::response('<html><body>Not Found</body></html>', 404),
            'https://example.com/geoflow/index.php/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => '二级目录站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com/geoflow',
            'front_mode' => 'static',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_sync_fallback',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_fallback_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('message', __('admin.distribution.message.settings_synced'));

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'endpoint_url' => 'https://example.com/geoflow/index.php',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow/geoflow-agent/v1/site-settings');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow/index.php/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update'));
    }

    public function test_admin_can_pause_distribution_channel_and_hide_it_from_task_form(): void
    {
        $admin = $this->admin();
        Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.pause', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'status' => 'paused',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertDontSee('官网主站');
    }

    public function test_admin_can_activate_paused_distribution_channel(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'paused',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.activate', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_rotate_distribution_channel_secret_once(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'toutiao-news-20260426',
            'site_settings' => [
                'site_name' => '远程门户',
                'site_subtitle' => '远程副标题',
                'site_description' => '远程站点描述',
                'site_keywords' => 'geo,remote',
                'copyright_info' => '© 2026 远程门户',
                'site_logo' => 'https://example.com/logo.png',
                'site_favicon' => 'https://example.com/favicon.ico',
                'seo_title_template' => '{title} - 远程门户',
                'seo_description_template' => '{description} - {site_name}',
                'featured_limit' => 7,
                'per_page' => 14,
            ],
            'status' => 'active',
        ]);
        $oldSecret = DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_oldsecret',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_oldsecret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'article.delete', 'health.check'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.rotate-secret', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('distribution_secret');

        $oldSecret->refresh();
        $this->assertSame('revoked', $oldSecret->status);

        $newSecret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->assertNotSame('gfk_oldsecret', $newSecret->key_id);
        $this->assertStringStartsWith('gfk_', (string) $newSecret->key_id);
        $this->assertStringStartsWith('gfsec_', (string) session('distribution_secret.secret'));
        $this->assertNotSame(session('distribution_secret.secret'), $newSecret->secret_ciphertext);
    }

    public function test_super_admin_can_reveal_distribution_channel_secret_with_current_password(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_reveal',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_reveal_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'article.delete', 'health.check'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.reveal-secret', ['channelId' => (int) $channel->id]), [
                'password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('distribution_secret.key_id', 'gfk_reveal')
            ->assertSessionHas('distribution_secret.secret', 'gfsec_reveal_secret')
            ->assertSessionHas('distribution_secret.endpoint_url', 'https://example.com');
    }

    public function test_distribution_channel_secret_reveal_requires_current_password(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_reveal',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_reveal_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.reveal-secret', ['channelId' => (int) $channel->id]), [
                'password' => 'wrong-password',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('distribution_secret');
    }

    public function test_distribution_channel_secret_reveal_requires_super_admin(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_reveal',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_reveal_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        $admin = Admin::query()->create([
            'username' => 'distribution_operator',
            'password' => 'secret-123',
            'email' => 'distribution-operator@example.com',
            'display_name' => 'Distribution Operator',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.reveal-secret', ['channelId' => (int) $channel->id]), [
                'password' => 'secret-123',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('distribution_secret');
    }

    public function test_distribution_channel_detail_guides_agent_deployment(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_reveal',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_reveal_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('目标站部署引导')
            ->assertSee('目标站点包 ZIP')
            ->assertSee('llms.txt')
            ->assertSee('sitemap.txt')
            ->assertSee('目标站点包')
            ->assertSee('下载站点包')
            ->assertSee('首页列表页')
            ->assertSee('文章详情页')
            ->assertSee('测试连接')
            ->assertSee('https://example.com/geoflow-agent/v1/health')
            ->assertSee('未部署目标站点包')
            ->assertSee('任务绑定渠道');
    }

    public function test_distribution_channel_detail_and_edit_show_copyable_rewrite_rules(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '二级目录站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com/geoflow/index.php',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('伪静态规则')
            ->assertSee('复制 Apache .htaccess')
            ->assertSee('复制 Nginx server 规则')
            ->assertSee('复制宝塔纯 rewrite 规则')
            ->assertSee('Nginx server 配置')
            ->assertSee('location = /geoflow/')
            ->assertSee('rewrite ^ /geoflow/index.php last;', false)
            ->assertSee('location /geoflow/', false)
            ->assertSee('try_files $uri /geoflow/index.php?$query_string;', false)
            ->assertSee('rewrite ^/geoflow/?$ /geoflow/index.php last;', false)
            ->assertSee('rewrite ^/geoflow/(geoflow-agent/.*)$ /geoflow/index.php/$1 last;', false)
            ->assertSee('rewrite ^/geoflow/(article/.*)$ /geoflow/index.php/$1 last;', false)
            ->assertDontSee('try_files $uri $uri/ /geoflow/index.php?$query_string;', false)
            ->assertSee('RewriteRule ^ index.php [L]', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('伪静态规则')
            ->assertSee('复制 Apache .htaccess')
            ->assertSee('复制 Nginx server 规则')
            ->assertSee('复制宝塔纯 rewrite 规则')
            ->assertSee('Nginx server 配置')
            ->assertSee('location = /geoflow/')
            ->assertSee('location /geoflow/', false)
            ->assertSee('try_files $uri /geoflow/index.php?$query_string;', false)
            ->assertSee('rewrite ^/geoflow/?$ /geoflow/index.php last;', false)
            ->assertSee('rewrite ^/geoflow/(geoflow-agent/.*)$ /geoflow/index.php/$1 last;', false)
            ->assertDontSee('try_files $uri $uri/ /geoflow/index.php?$query_string;', false);
    }

    public function test_health_check_404_explains_missing_agent_package_without_raw_html(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/health' => Http::response('<!DOCTYPE HTML><html><body><h1>Not Found</h1></body></html>', 404),
            'https://example.com/index.php/geoflow-agent/v1/health' => Http::response('<!DOCTYPE HTML><html><body><h1>Not Found</h1></body></html>', 404),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => '未部署站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]));

        $response->assertRedirect();
        $errors = session('errors')?->getBag('default')->all() ?? [];
        $this->assertNotEmpty($errors);
        $message = implode("\n", $errors);
        $this->assertStringContainsString('目标站 Agent 接口未找到', $message);
        $this->assertStringContainsString('目标站点包', $message);
        $this->assertStringNotContainsString('<!DOCTYPE', $message);

        $channel->refresh();
        $this->assertSame('failed', $channel->last_health_status);
        $this->assertStringContainsString('目标站 Agent 接口未找到', (string) $channel->last_error_message);
    }

    public function test_health_check_falls_back_to_index_php_agent_entry_and_updates_endpoint_url(): void
    {
        Http::fake([
            'https://example.com/geoflow/geoflow-agent/v1/health' => Http::response('<html><body>Not Found</body></html>', 404),
            'https://example.com/geoflow/index.php/geoflow-agent/v1/health' => Http::response([
                'ok' => true,
                'service' => 'geoflow-target-site',
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => '二级目录站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com/geoflow',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_health_fallback',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_health_fallback_secret'),
            'status' => 'active',
            'scopes' => ['health.check'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.health', ['channelId' => (int) $channel->id]))
            ->assertRedirect()
            ->assertSessionHas('message');

        $channel->refresh();
        $this->assertSame('ok', $channel->last_health_status);
        $this->assertSame('https://example.com/geoflow/index.php', $channel->endpoint_url);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow/geoflow-agent/v1/health');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow/index.php/geoflow-agent/v1/health'
            && $request->hasHeader('X-GEOFlow-Event', 'health.check'));
    }

    public function test_super_admin_can_download_channel_target_site_package_with_current_password(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_text_ads'],
            ['setting_value' => json_encode([
                [
                    'id' => 'package-text-ad',
                    'name' => 'Package Text Ad',
                    'placement' => 'content_top',
                    'text' => 'Package CTA',
                    'url' => '/package-offer',
                    'text_color' => '#2563eb',
                    'open_new_tab' => false,
                    'tracking_enabled' => false,
                    'tracking_param' => '',
                    'enabled' => true,
                    'sort_order' => 10,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'toutiao-news-20260426',
            'site_settings' => [
                'site_name' => '远程门户',
                'site_subtitle' => '远程副标题',
                'site_description' => '远程站点描述',
                'site_keywords' => 'geo,remote',
                'copyright_info' => '© 2026 远程门户',
                'site_logo' => 'https://example.com/logo.png',
                'site_favicon' => 'https://example.com/favicon.ico',
                'seo_title_template' => '{title} - 远程门户',
                'seo_description_template' => '{description} - {site_name}',
                'featured_limit' => 7,
                'per_page' => 14,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_package',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_package_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'article.delete', 'health.check'],
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.download-package', ['channelId' => (int) $channel->id]), [
                'package_password' => 'secret-123',
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('geoflow-target-site-example-com.zip', (string) $response->headers->get('content-disposition'));

        $zipPath = tempnam(sys_get_temp_dir(), 'geoflow-target-site-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $config = (string) $zip->getFromName('config.php');
        $this->assertStringContainsString("'key_id' => 'gfk_package'", $config);
        $this->assertStringContainsString("'secret' => 'gfsec_package_secret'", $config);
        $this->assertStringContainsString("'front_mode' => 'static'", $config);
        $this->assertStringContainsString("'static_publish_enabled' => true", $config);
        $this->assertStringContainsString("'static_output_dir' => __DIR__", $config);
        $this->assertStringContainsString("'site_name' => '远程门户'", $config);
        $this->assertStringContainsString("'site_subtitle' => '远程副标题'", $config);
        $this->assertStringContainsString("'copyright_info' => '© 2026 远程门户'", $config);
        $this->assertStringContainsString("'active_theme' => 'toutiao-news-20260426'", $config);
        $this->assertStringContainsString("'per_page' => 14", $config);
        $this->assertStringContainsString("'article_text_ads' =>", $config);
        $this->assertStringContainsString("'Package CTA'", $config);

        $rootIndex = (string) $zip->getFromName('index.php');
        $this->assertStringContainsString("require __DIR__.'/public/index.php';", $rootIndex);

        $staticIndex = (string) $zip->getFromName('index.html');
        $this->assertStringContainsString('远程门户', $staticIndex);
        $this->assertStringContainsString('暂无文章', $staticIndex);
        $this->assertStringContainsString('assets/css/site.css', $staticIndex);
        $this->assertStringContainsString('class="target-theme-toutiao"', $staticIndex);
        $this->assertStringNotContainsString('<style>', $staticIndex);
        $this->assertStringNotContainsString('</style>', $staticIndex);
        $this->assertStringContainsString('# 远程门户', (string) $zip->getFromName('llms.txt'));
        $this->assertStringContainsString('https://example.com/', (string) $zip->getFromName('sitemap.txt'));
        $this->assertFalse($zip->locateName('README.md'));

        $siteCss = (string) $zip->getFromName('assets/css/site.css');
        $siteJs = (string) $zip->getFromName('assets/js/site.js');
        $channel->refresh();
        $expectedAssetVersion = substr(hash('sha256', implode('|', [
            (string) ($channel->template_key ?? ''),
            (string) ($channel->updated_at ?? ''),
            (string) config('geoflow.app_version', ''),
            hash('sha256', $siteCss),
            hash('sha256', $siteJs),
        ])), 0, 12);
        $this->assertStringContainsString('assets/css/site.css?v='.$expectedAssetVersion, $staticIndex);
        $this->assertStringContainsString('assets/js/site.js?v='.$expectedAssetVersion, $staticIndex);
        $this->assertStringContainsString('.content img', $siteCss);
        $this->assertStringContainsString('data-copy-target', $siteJs);
        $this->assertStringContainsString('body.target-theme-toutiao', $siteCss);
        $this->assertStringContainsString('body.target-theme-netease', $siteCss);
        $this->assertStringContainsString('body.target-theme-tdwh', $siteCss);
        $this->assertNotFalse($zip->getFromName('assets/images/.gitkeep'));

        $rootHtaccess = (string) $zip->getFromName('.htaccess');
        $this->assertStringContainsString('config\\.php', $rootHtaccess);
        $this->assertStringContainsString('storage/', $rootHtaccess);
        $this->assertStringContainsString('[F,L]', $rootHtaccess);

        $storageHtaccess = (string) $zip->getFromName('storage/.htaccess');
        $this->assertStringContainsString('Require all denied', $storageHtaccess);

        $nginxExample = (string) $zip->getFromName('nginx.example.conf');
        $this->assertStringContainsString('location ~ ^/(config\\.php|storage/|nginx\\.example\\.conf|nginx\\.rewrite\\.conf|bt\\.rewrite\\.conf)', $nginxExample);
        $this->assertStringNotContainsString('README\\.md', $nginxExample);
        $this->assertStringContainsString('deny all;', $nginxExample);
        $this->assertStringContainsString('nginx\\.rewrite\\.conf', $nginxExample);
        $this->assertStringContainsString('bt\\.rewrite\\.conf', $nginxExample);

        $nginxRewrite = (string) $zip->getFromName('nginx.rewrite.conf');
        $this->assertStringContainsString('location = /', $nginxRewrite);
        $this->assertStringContainsString('rewrite ^ /index.php last;', $nginxRewrite);
        $this->assertStringContainsString('location /', $nginxRewrite);
        $this->assertStringContainsString('try_files $uri /index.php?$query_string;', $nginxRewrite);
        $this->assertStringNotContainsString('try_files $uri $uri/ /index.php?$query_string;', $nginxRewrite);

        $btRewrite = (string) $zip->getFromName('bt.rewrite.conf');
        $this->assertStringContainsString('rewrite ^/?$ /index.php last;', $btRewrite);
        $this->assertStringContainsString('rewrite ^/(geoflow-agent/.*)$ /index.php/$1 last;', $btRewrite);
        $this->assertStringContainsString('rewrite ^/(article/.*)$ /index.php/$1 last;', $btRewrite);

        $frontController = (string) $zip->getFromName('public/index.php');
        $this->assertStringContainsString('/geoflow-agent/v1/health', $frontController);
        $this->assertStringContainsString('/geoflow-agent/v1/articles', $frontController);
        $this->assertStringContainsString('/geoflow-agent/v1/articles/{slug}/update', $frontController);
        $this->assertStringContainsString('/geoflow-agent/v1/articles/{slug}/delete', $frontController);
        $this->assertStringContainsString('/geoflow-agent/v1/site-settings', $frontController);
        $this->assertStringContainsString('function articleContentHtml', $frontController);
        $this->assertStringContainsString('function markdownToHtml', $frontController);
        $this->assertStringContainsString('function stripLeadingTitleHeading', $frontController);
        $this->assertStringContainsString('function keywordTags', $frontController);
        $this->assertStringContainsString("preg_match('~^(?:https?://|/|#)~i'", $frontController);
        $this->assertStringNotContainsString("preg_match('#^(https?://|/|#)#i'", $frontController);
        $this->assertStringContainsString("article['content_html']", $frontController);
        $this->assertStringContainsString('article-table-wrap', $frontController);
        $this->assertStringContainsString('class="tags"', $frontController);
        $this->assertStringContainsString('.content h2', $siteCss);
        $this->assertStringContainsString('.article-text-ads', $siteCss);
        $this->assertStringContainsString('.article-text-ad-module', $siteCss);
        $this->assertStringContainsString('function activeTheme', $frontController);
        $this->assertStringContainsString('function themeClass', $frontController);
        $this->assertStringContainsString('function normalizeArticleTextAds', $frontController);
        $this->assertStringContainsString('function normalizeArticleTextAdLinks', $frontController);
        $this->assertStringContainsString('function renderArticleTextAds', $frontController);
        $this->assertStringContainsString('data-module-id', $frontController);
        $this->assertStringContainsString("str_ends_with(\$baseUrl, '?')", $frontController);
        $this->assertStringContainsString("renderArticleTextAds(\$settings, 'content_top')", $frontController);
        $this->assertStringContainsString("renderArticleTextAds(\$settings, 'content_bottom')", $frontController);
        $this->assertStringNotContainsString('function themeStyles', $frontController);
        $this->assertStringContainsString('target-theme-toutiao', $frontController);
        $this->assertStringContainsString('activeTheme($settings)', $frontController);
        $this->assertStringContainsString("str_starts_with(\$path, '/index.php/')", $frontController);
        $this->assertStringContainsString('handleSiteSettingsUpdate', $frontController);
        $this->assertStringContainsString('renderHomePage', $frontController);
        $this->assertStringContainsString('renderArticlePage', $frontController);
        $this->assertStringContainsString('function staticPublishEnabled', $frontController);
        $this->assertStringContainsString('function staticSitePath', $frontController);
        $this->assertStringContainsString('function frontSitePath', $frontController);
        $this->assertStringContainsString('function rebuildStaticSite', $frontController);
        $this->assertStringContainsString('function pruneStaticArticlePages', $frontController);
        $this->assertStringContainsString('pruneStaticArticlePages($config, $activeSlugs)', $frontController);
        $this->assertStringContainsString('function frontAssetPath', $frontController);
        $this->assertStringContainsString('function jsonLdScript', $frontController);
        $this->assertStringContainsString('function localizeArticleAssets', $frontController);
        $this->assertStringContainsString('application/ld+json', $frontController);
        $this->assertStringContainsString('"@type"=>"Article"', $frontController);
        $this->assertStringContainsString('assets/images', $frontController);
        $this->assertStringContainsString('assets/css/site.css', $frontController);
        $this->assertStringNotContainsString('<style>', $frontController);
        $this->assertStringNotContainsString('</style>', $frontController);
        $this->assertStringNotContainsString('themeStyles($settings)', $frontController);
        $this->assertStringContainsString('function writeJsonFile', $frontController);
        $this->assertStringContainsString('article_storage_not_writable', $frontController);
        $this->assertStringContainsString('site_settings_not_writable', $frontController);
        $this->assertStringContainsString('function renderLlmsText', $frontController);
        $this->assertStringContainsString('function renderSitemapText', $frontController);
        $this->assertStringContainsString('function maxAssetBytes', $frontController);
        $this->assertStringContainsString('stream_context_create', $frontController);
        $this->assertStringContainsString("writeStaticFile(\$config, 'llms.txt'", $frontController);
        $this->assertStringContainsString("writeStaticFile(\$config, 'sitemap.txt'", $frontController);
        $this->assertStringContainsString('textResponse(renderLlmsText($config))', $frontController);
        $this->assertStringContainsString("writeStaticFile(\$config, 'index.html'", $frontController);
        $this->assertStringContainsString("writeStaticFile(\$config, 'article/'.safeFileName(\$slug).'/index.html'", $frontController);
        $this->assertStringContainsString('rebuildStaticSite($config)', $frontController);
        $this->assertStringContainsString("'removed' => \$removed", $frontController);
        $this->assertStringContainsString("frontSiteUrl(\$config, '/article/'.rawurlencode(\$slug))", $frontController);

        $zip->close();
        unlink($zipPath);
    }

    public function test_fashion_target_site_package_is_self_contained_without_google_fonts(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Fashion Insight',
            'domain' => 'fashion.example.com',
            'endpoint_url' => 'https://fashion.example.com',
            'template_key' => 'fashion-insight',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_fashion',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_fashion_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'health.check'],
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.download-package', ['channelId' => (int) $channel->id]), [
                'package_password' => 'secret-123',
            ]);

        $response->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'geoflow-target-site-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $staticIndex = (string) $zip->getFromName('index.html');
        $frontController = (string) $zip->getFromName('public/index.php');
        $siteCss = (string) $zip->getFromName('assets/css/site.css');

        $this->assertStringContainsString('class="target-theme-fashion"', $staticIndex);
        $this->assertStringContainsString('body.target-theme-fashion', $siteCss);
        $this->assertStringNotContainsString('fonts.googleapis.com', $frontController);
        $this->assertStringNotContainsString('fonts.gstatic.com', $frontController);
        $this->assertStringNotContainsString('fonts.googleapis.com', $staticIndex);

        $zip->close();
        unlink($zipPath);
    }

    public function test_channel_target_site_package_supports_agent_endpoint_under_subdirectory(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '二级目录站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com/geoflow-target-site/index.php',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_subdir',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_subdir_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'health.check'],
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.download-package', ['channelId' => (int) $channel->id]), [
                'package_password' => 'secret-123',
            ]);

        $response->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'geoflow-target-site-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $config = (string) $zip->getFromName('config.php');
        $this->assertStringContainsString("'public_base_url' => 'https://example.com/geoflow-target-site/index.php'", $config);
        $this->assertStringContainsString("'base_path' => '/geoflow-target-site'", $config);
        $this->assertStringContainsString("'front_mode' => 'static'", $config);

        $nginxRewrite = (string) $zip->getFromName('nginx.rewrite.conf');
        $this->assertStringContainsString('location = /geoflow-target-site/', $nginxRewrite);
        $this->assertStringContainsString('rewrite ^ /geoflow-target-site/index.php last;', $nginxRewrite);
        $this->assertStringContainsString('location /geoflow-target-site/', $nginxRewrite);
        $this->assertStringContainsString('try_files $uri /geoflow-target-site/index.php?$query_string;', $nginxRewrite);
        $this->assertStringNotContainsString('try_files $uri $uri/ /geoflow-target-site/index.php?$query_string;', $nginxRewrite);
        $this->assertStringContainsString('^/geoflow-target-site/(config\\.php|nginx\\.example\\.conf|nginx\\.rewrite\\.conf|bt\\.rewrite\\.conf|storage/)', $nginxRewrite);
        $this->assertStringNotContainsString('README\\.md', $nginxRewrite);

        $btRewrite = (string) $zip->getFromName('bt.rewrite.conf');
        $this->assertStringContainsString('rewrite ^/geoflow-target-site/?$ /geoflow-target-site/index.php last;', $btRewrite);
        $this->assertStringContainsString('rewrite ^/geoflow-target-site/(geoflow-agent/.*)$ /geoflow-target-site/index.php/$1 last;', $btRewrite);
        $this->assertStringContainsString('rewrite ^/geoflow-target-site/(article/.*)$ /geoflow-target-site/index.php/$1 last;', $btRewrite);

        $frontController = (string) $zip->getFromName('public/index.php');
        $this->assertStringContainsString('function normalizeRequestPath', $frontController);
        $this->assertStringContainsString('function sitePath', $frontController);
        $this->assertStringContainsString('verifySignedRequest($config, $method, $path, $body)', $frontController);
        $this->assertStringContainsString("frontSitePath(\$config, '/article/'.rawurlencode(\$slug))", $frontController);

        $zip->close();
        unlink($zipPath);
    }

    public function test_apparel_target_site_package_does_not_render_dead_category_navigation(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '服装情报站',
            'domain' => 'apparel.example.com',
            'endpoint_url' => 'https://apparel.example.com',
            'template_key' => 'apparel-sourcing-intelligence',
            'site_settings' => [
                'site_name' => 'Apparel Intelligence',
                'site_description' => 'Global sourcing reports',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_apparel',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_apparel_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'health.check'],
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.download-package', ['channelId' => (int) $channel->id]), [
                'package_password' => 'secret-123',
            ]);

        $zipPath = tempnam(sys_get_temp_dir(), 'geoflow-target-site-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $staticIndex = (string) $zip->getFromName('index.html');
        $this->assertStringContainsString('assets/css/site.css?v=', $staticIndex);
        $this->assertStringContainsString('assets/js/site.js?v=', $staticIndex);
        $this->assertStringContainsString('class="target-theme-apparel"', $staticIndex);

        $siteCss = (string) $zip->getFromName('assets/css/site.css');
        $this->assertStringContainsString('target-theme-apparel', $siteCss);
        $this->assertSame(1, substr_count($siteCss, 'body.target-theme-fashion{'));

        $frontController = (string) $zip->getFromName('public/index.php');
        $this->assertStringContainsString('function frontVersionedAssetPath', $frontController);
        $this->assertStringNotContainsString("foreach (array_slice(siteCategories(\$config), 0, 7)", $frontController);
        $this->assertStringNotContainsString("frontSitePath(\$config, '/')\">'.h((string) \$category['name'])", $frontController);

        $zip->close();
        unlink($zipPath);
    }

    public function test_rewrite_mode_package_disables_static_publish(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '伪静态站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com/geoflow/index.php',
            'front_mode' => 'rewrite',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_rewrite',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_rewrite_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'health.check'],
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.download-package', ['channelId' => (int) $channel->id]), [
                'package_password' => 'secret-123',
            ]);

        $zipPath = tempnam(sys_get_temp_dir(), 'geoflow-target-site-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $config = (string) $zip->getFromName('config.php');
        $this->assertStringContainsString("'front_mode' => 'rewrite'", $config);
        $this->assertStringContainsString("'static_publish_enabled' => false", $config);
        $zip->close();
        unlink($zipPath);
    }

    public function test_channel_target_site_package_download_requires_current_password(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_package',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_package_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.download-package', ['channelId' => (int) $channel->id]), [
                'package_password' => 'wrong-password',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('package_password');
    }

    public function test_admin_can_sync_channel_site_settings_to_remote_agent(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'toutiao-news-20260426',
            'site_settings' => [
                'site_name' => '远程门户',
                'site_description' => '远程站点描述',
                'copyright_info' => '© 2026 远程门户',
                'per_page' => 14,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_settings',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_settings_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update')
            && $request['settings']['site_name'] === '远程门户'
            && $request['settings']['active_theme'] === 'toutiao-news-20260426'
            && $request['settings']['front_mode'] === 'static'
            && $request['settings']['per_page'] === 14);

        $this->assertDatabaseHas('distribution_logs', [
            'distribution_channel_id' => (int) $channel->id,
            'event' => 'site.settings.synced',
        ]);
    }

    public function test_sync_channel_settings_requeues_existing_articles_for_target_refresh(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'toutiao-news-20260426',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_settings',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_settings_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update', 'article.update'],
        ]);
        $article = Article::query()->create([
            'title' => '已有远端文章',
            'slug' => 'existing-remote-article',
            'excerpt' => '摘要',
            'content' => "正文\n\n![图](/storage/uploads/images/2026/05/demo.png)",
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $deletedArticle = Article::query()->create([
            'title' => '已删除远端副本文章',
            'slug' => 'deleted-remote-copy',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_url' => 'https://example.com/article/existing-remote-article',
            'idempotency_key' => 'old-key',
        ]);
        $deletedDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $deletedArticle->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'delete',
            'status' => 'synced',
            'idempotency_key' => 'deleted-key',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('message', __('admin.distribution.message.settings_synced_with_content_refresh', ['count' => 1]));

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'action' => 'update',
            'status' => 'queued',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-update-v1',
        ]);
        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $deletedDistribution->id,
            'action' => 'delete',
            'status' => 'synced',
            'idempotency_key' => 'deleted-key',
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_task_create_page_lists_active_distribution_channels(): void
    {
        Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('官网主站')
            ->assertSee('example.com')
            ->assertSee('本地和渠道站点同时发布')
            ->assertSee('仅发布到渠道站点')
            ->assertSee('仅发布到本站');
    }

    public function test_task_creation_persists_selected_distribution_channels(): void
    {
        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '分发任务',
                'title_library_id' => $fixtures['title_library']->id,
                'prompt_id' => $fixtures['prompt']->id,
                'ai_model_id' => $fixtures['ai_model']->id,
                'author_id' => $fixtures['author']->id,
                'fixed_category_id' => $fixtures['category']->id,
                'status' => 'paused',
                'publish_scope' => 'distribution_only',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'distribution_channel_ids' => [(string) $channel->id],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '分发任务')->firstOrFail();
        $this->assertSame('distribution_only', (string) $task->publish_scope);
        $this->assertDatabaseHas('task_distribution_channels', [
            'task_id' => (int) $task->id,
            'distribution_channel_id' => (int) $channel->id,
        ]);
    }

    public function test_task_edit_keeps_distribution_only_scope_selected(): void
    {
        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '仅分发任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'author_id' => $fixtures['author']->id,
            'fixed_category_id' => $fixtures['category']->id,
            'status' => 'paused',
            'publish_scope' => 'distribution_only',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
            'category_mode' => 'fixed',
        ]);
        $task->distributionChannels()->syncWithPivotValues([(int) $channel->id], [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('value="distribution_only" checked', false)
            ->assertSee('value="'.$channel->id.'" checked', false);
    }

    public function test_publishing_task_article_creates_distribution_record(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '分发来源任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->distributionChannels()->sync([(int) $channel->id]);
        $article = Article::query()->create([
            'title' => '待发布分发文章',
            'slug' => 'distribution-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'published_at' => null,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.articles.batch.update-status'), [
                'article_ids' => [(string) $article->id],
                'new_status' => 'published',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class);
    }

    public function test_distribution_scope_controls_remote_queue_visibility(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '渠道站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        $distributionOnlyTask = Task::query()->create([
            'name' => '仅渠道任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $distributionOnlyTask->distributionChannels()->sync([(int) $channel->id]);
        $distributionOnlyArticle = Article::query()->create([
            'title' => '仅分发到渠道的文章',
            'slug' => 'remote-only-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $distributionOnlyTask->id,
            'status' => 'private',
            'review_status' => 'approved',
            'published_at' => null,
        ]);

        app(DistributionOrchestrator::class)->enqueueForArticle($distributionOnlyArticle);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $distributionOnlyArticle->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
        ]);

        $localOnlyTask = Task::query()->create([
            'name' => '仅本站任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'local_only',
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $localOnlyTask->distributionChannels()->sync([(int) $channel->id]);
        $localOnlyArticle = Article::query()->create([
            'title' => '仅本站文章',
            'slug' => 'local-only-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $localOnlyTask->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        app(DistributionOrchestrator::class)->enqueueForArticle($localOnlyArticle);

        $this->assertDatabaseMissing('article_distributions', [
            'article_id' => (int) $localOnlyArticle->id,
            'distribution_channel_id' => (int) $channel->id,
        ]);
    }

    public function test_distribution_signature_headers_include_body_hash_and_idempotency_key(): void
    {
        $plainSecret = 'gfsec_test_secret';
        $secret = new DistributionChannelSecret([
            'key_id' => 'gfk_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt($plainSecret),
            'scopes' => ['article.publish'],
        ]);
        $body = '{"ok":true}';

        $headers = app(DistributionSigningService::class)->headers(
            $secret,
            'POST',
            '/geoflow-agent/v1/articles',
            $body,
            'article.publish',
            'article-1-channel-1-publish-v1'
        );

        $this->assertSame('article-1-channel-1-publish-v1', $headers['X-GEOFlow-Idempotency-Key']);
        $this->assertSame(hash('sha256', $body), $headers['X-GEOFlow-Body-SHA256']);
        $this->assertNotEmpty($headers['X-GEOFlow-Signature']);
    }

    public function test_distribution_post_requests_fall_back_to_index_php_entry_when_rewrite_is_missing(): void
    {
        Http::fake([
            'https://example.com/geoflow/geoflow-agent/v1/articles' => Http::response('<html><body>Not Found</body></html>', 404),
            'https://example.com/geoflow/index.php/geoflow-agent/v1/articles' => Http::response([
                'ok' => true,
                'remote_id' => 'remote-index-entry',
                'remote_url' => 'https://example.com/geoflow/article/index-entry/',
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '二级目录站点',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com/geoflow',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_index_fallback',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_index_fallback_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        $article = Article::query()->create([
            'title' => '免伪静态分发文章',
            'slug' => 'index-entry-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'status' => 'synced',
            'remote_id' => 'remote-index-entry',
        ]);
        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'endpoint_url' => 'https://example.com/geoflow/index.php',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow/geoflow-agent/v1/articles');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow/index.php/geoflow-agent/v1/articles'
            && $request->hasHeader('X-GEOFlow-Event', 'article.publish'));
    }

    public function test_distribution_process_sends_signed_payload_and_records_remote_result(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/articles' => Http::response([
                'ok' => true,
                'remote_id' => 'remote-123',
                'remote_url' => 'https://example.com/article/remote-123',
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_test_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        $article = Article::query()->create([
            'title' => '已发布分发文章',
            'slug' => 'published-distribution-article',
            'excerpt' => '摘要',
            'content' => <<<'MD'
# 已发布分发文章

## 核心摘要

- **提及率**：品牌被 AI 回答提及的比例。
- **推荐语境**：AI 回答中对品牌的态度。

| 指标 | 说明 |
| --- | --- |
| API | 已配置 |

![333.png](/uploads/images/2026/04/demo.png)
MD,
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'status' => 'synced',
            'remote_id' => 'remote-123',
            'remote_url' => 'https://example.com/article/remote-123',
        ]);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-GEOFlow-Key-Id', 'gfk_test')
            && $request->hasHeader('X-GEOFlow-Idempotency-Key', 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1')
            && $request->url() === 'https://example.com/geoflow-agent/v1/articles'
            && str_contains((string) $request['article']['content_html'], '<h2>核心摘要</h2>')
            && str_contains((string) $request['article']['content_html'], '<strong>提及率</strong>')
            && str_contains((string) $request['article']['content_html'], '<div class="article-table-wrap"><table class="article-table">')
            && str_contains((string) $request['article']['content_html'], 'src="/storage/uploads/images/2026/04/demo.png"')
            && ! str_contains((string) $request['article']['content_html'], '<h1>已发布分发文章</h1>'));
    }

    public function test_wordpress_distribution_queue_process_publishes_article_and_records_remote_result(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/geo-article/',
            ], 201),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => 'WordPress 站点',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'draft',
                'wordpress_category_strategy' => 'fixed',
                'wordpress_fixed_category' => '',
                'wordpress_tag_strategy' => 'disabled',
                'wordpress_image_strategy' => 'keep_original',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_queue',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);
        $article = Article::query()->create([
            'title' => 'WordPress 队列文章',
            'slug' => 'geo-article',
            'excerpt' => '摘要',
            'content' => "## 核心观点\n\n正文",
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'status' => 'synced',
            'remote_id' => '123',
            'remote_url' => 'https://wp.example.com/geo-article/',
        ]);
        $distribution->refresh();
        $this->assertSame(123, $distribution->remote_meta['wordpress_post_id'] ?? null);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts'
            && $request['title'] === 'WordPress 队列文章'
            && $request['status'] === 'draft'
            && str_contains((string) $request['content'], '<h2>核心观点</h2>'));
    }

    public function test_distribution_payload_embeds_local_image_assets_for_target_site(): void
    {
        $fixtures = $this->taskFixtures();
        $imagePath = storage_path('app/public/uploads/images/2026/05/distribution-demo.png');
        if (! is_dir(dirname($imagePath))) {
            mkdir(dirname($imagePath), 0755, true);
        }
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=') ?: '');

        $article = Article::query()->create([
            'title' => '带图片分发文章',
            'slug' => 'article-with-image',
            'excerpt' => '摘要',
            'content' => "正文\n\n![示例图](/storage/uploads/images/2026/05/distribution-demo.png)",
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $payload = app(DistributionPayloadBuilder::class)->build($article);

        $this->assertIsArray($payload['assets']['images'] ?? null);
        $this->assertCount(1, $payload['assets']['images']);
        $image = $payload['assets']['images'][0];
        $this->assertSame('/storage/uploads/images/2026/05/distribution-demo.png', $image['source_url']);
        $this->assertSame('image/png', $image['mime_type']);
        $this->assertNotEmpty($image['content_base64']);
        $this->assertStringContainsString('src="/storage/uploads/images/2026/05/distribution-demo.png"', (string) $payload['article']['content_html']);
    }

    public function test_distribution_payload_includes_selected_article_hero_image_asset(): void
    {
        $fixtures = $this->taskFixtures();
        $imagePath = storage_path('app/public/uploads/images/2026/05/hero-demo.png');
        if (! is_dir(dirname($imagePath))) {
            mkdir(dirname($imagePath), 0755, true);
        }
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=') ?: '');

        $article = Article::query()->create([
            'title' => '封面图分发文章',
            'slug' => 'article-with-hero-image',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $library = ImageLibrary::query()->create([
            'name' => '封面图库',
        ]);
        $image = Image::query()->create([
            'library_id' => (int) $library->id,
            'filename' => 'hero-demo.png',
            'original_name' => 'hero-demo.png',
            'file_name' => 'hero-demo.png',
            'file_path' => 'storage/uploads/images/2026/05/hero-demo.png',
            'file_size' => 67,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
        ]);
        ArticleImage::query()->create([
            'article_id' => (int) $article->id,
            'image_id' => (int) $image->id,
            'position' => 1,
        ]);

        try {
            $payload = app(DistributionPayloadBuilder::class)->build($article);
        } finally {
            @unlink($imagePath);
        }

        $this->assertSame('/storage/uploads/images/2026/05/hero-demo.png', $payload['article']['hero_image_url'] ?? null);
        $this->assertCount(1, $payload['assets']['images'] ?? []);
        $this->assertSame('/storage/uploads/images/2026/05/hero-demo.png', $payload['assets']['images'][0]['source_url'] ?? null);
        $this->assertSame('image/png', $payload['assets']['images'][0]['mime_type'] ?? null);
        $this->assertNotEmpty($payload['assets']['images'][0]['content_base64'] ?? '');
    }

    public function test_distribution_payload_does_not_embed_oversized_local_image_assets(): void
    {
        $fixtures = $this->taskFixtures();
        $imagePath = storage_path('app/public/uploads/images/2026/05/distribution-large.png');
        if (! is_dir(dirname($imagePath))) {
            mkdir(dirname($imagePath), 0755, true);
        }
        file_put_contents($imagePath, str_repeat('x', 6 * 1024 * 1024));

        $article = Article::query()->create([
            'title' => '大图分发文章',
            'slug' => 'article-with-large-image',
            'excerpt' => '摘要',
            'content' => "正文\n\n![大图](/storage/uploads/images/2026/05/distribution-large.png)",
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        try {
            $payload = app(DistributionPayloadBuilder::class)->build($article);
        } finally {
            @unlink($imagePath);
        }

        $this->assertIsArray($payload['assets']['images'] ?? null);
        $this->assertCount(1, $payload['assets']['images']);
        $image = $payload['assets']['images'][0];
        $this->assertSame('/storage/uploads/images/2026/05/distribution-large.png', $image['source_url']);
        $this->assertArrayNotHasKey('content_base64', $image);
        $this->assertSame('file_too_large', $image['skip_reason'] ?? null);
    }

    public function test_admin_can_edit_remote_article_and_write_back_local_article(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/articles/remote-edit-article/update' => Http::response([
                'ok' => true,
                'remote_id' => 'geoflow-remote-edit-article',
                'remote_url' => 'https://example.com/article/remote-edit-article/',
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_remote_edit',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_remote_edit_secret'),
            'status' => 'active',
            'scopes' => ['article.publish', 'article.update'],
        ]);
        $article = Article::query()->create([
            'title' => '远端旧标题',
            'slug' => 'remote-edit-article',
            'excerpt' => '旧摘要',
            'content' => '旧正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'geoflow-remote-edit-article',
            'remote_url' => 'https://example.com/article/remote-edit-article/',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.edit', ['distributionId' => (int) $distribution->id]))
            ->assertOk()
            ->assertSee('编辑远端文章')
            ->assertSee('远端旧标题');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.update', ['distributionId' => (int) $distribution->id]), [
                'title' => '远端新标题',
                'excerpt' => '新摘要',
                'content' => "## 新正文\n\n已修正。",
                'keywords' => 'geo,分发',
                'meta_description' => '新的描述',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $article->refresh();
        $this->assertSame('远端新标题', (string) $article->title);
        $this->assertSame("## 新正文\n\n已修正。", (string) $article->content);
        $this->assertSame('geo,分发', (string) $article->keywords);

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'action' => 'update',
            'status' => 'synced',
            'remote_url' => 'https://example.com/article/remote-edit-article/',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow-agent/v1/articles/remote-edit-article/update'
            && $request->hasHeader('X-GEOFlow-Event', 'article.update')
            && $request['article']['title'] === '远端新标题'
            && str_contains((string) $request['article']['content_html'], '<h2>新正文</h2>'));
    }

    public function test_admin_can_delete_remote_article_copy_and_refresh_target_site(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/articles/remote-delete-article/delete' => Http::response([
                'ok' => true,
                'deleted' => true,
                'static' => ['enabled' => true, 'articles' => 0],
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_remote_delete',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_remote_delete_secret'),
            'status' => 'active',
            'scopes' => ['article.delete'],
        ]);
        $article = Article::query()->create([
            'title' => '远端待删除文章',
            'slug' => 'remote-delete-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'geoflow-remote-delete-article',
            'remote_url' => 'https://example.com/article/remote-delete-article/',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.article.delete', ['distributionId' => (int) $distribution->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'action' => 'delete',
            'status' => 'synced',
            'remote_url' => null,
        ]);
        $this->assertDatabaseHas('articles', [
            'id' => (int) $article->id,
            'title' => '远端待删除文章',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/geoflow-agent/v1/articles/remote-delete-article/delete'
            && $request->hasHeader('X-GEOFlow-Event', 'article.delete')
            && $request['article']['slug'] === 'remote-delete-article');
    }

    public function test_admin_can_delete_remote_article_copy_as_json_without_redirect(): void
    {
        Http::fake([
            'https://example.com/geoflow-agent/v1/articles/ajax-delete-article/delete' => Http::response([
                'ok' => true,
                'deleted' => true,
                'static' => ['enabled' => true, 'articles' => 0],
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_ajax_delete',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_ajax_delete_secret'),
            'status' => 'active',
            'scopes' => ['article.delete'],
        ]);
        $article = Article::query()->create([
            'title' => 'AJAX 删除文章',
            'slug' => 'ajax-delete-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'geoflow-ajax-delete-article',
            'remote_url' => 'https://example.com/article/ajax-delete-article/',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('admin.distribution.article.delete', ['distributionId' => (int) $distribution->id]))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => __('admin.distribution.message.remote_article_deleted'),
                'job' => [
                    'id' => (int) $distribution->id,
                    'action' => 'delete',
                    'status' => 'synced',
                    'remote_url' => null,
                ],
            ]);
    }

    public function test_deleted_remote_copy_job_is_read_only_in_channel_detail(): void
    {
        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '已移除渠道文章',
            'slug' => 'deleted-remote-copy',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'delete',
            'status' => 'synced',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-delete-v1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.job_state.remote_copy_deleted'))
            ->assertDontSee(__('admin.distribution.button.edit_remote_article'))
            ->assertDontSee(__('admin.distribution.button.delete_remote_article'));
    }

    public function test_distribution_jobs_table_uses_compact_non_wrapping_columns_and_ajax_delete_form(): void
    {
        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => 'AL领导力',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '较长的分发队列文章标题用于换行展示',
            'slug' => 'compact-jobs-table-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_url' => 'https://example.com/geoflow/article/compact-jobs-table-article/',
            'attempt_count' => 3,
            'idempotency_key' => 'compact-jobs-table',
        ]);
        config(['app.url' => 'https://configured.example']);
        $deletePath = route('admin.distribution.article.delete', ['distributionId' => (int) $distribution->id], false);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('data-distribution-delete-form', false)
            ->assertSee('data-distribution-delete-status', false)
            ->assertSee('action="'.$deletePath.'"', false)
            ->assertDontSee('https://configured.example'.$deletePath, false)
            ->assertSee('whitespace-nowrap', false)
            ->assertSee('break-words', false)
            ->assertSee('break-all', false);
    }

    public function test_distribution_channel_detail_logs_show_time_and_article_title(): void
    {
        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => 'AL领导力',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '日志关联文章标题',
            'slug' => 'log-related-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        DistributionLog::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'article_id' => (int) $article->id,
            'level' => 'info',
            'event' => 'article.update',
            'message' => '远端文章已更新',
            'created_at' => now()->setDate(2026, 5, 20)->setTime(17, 45),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('日志关联文章标题')
            ->assertSee('2026-05-20 17:45')
            ->assertSee(__('admin.distribution.field.article'))
            ->assertSee(__('admin.distribution.field.event'));
    }

    public function test_admin_can_retry_failed_distribution_job(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '重试分发文章',
            'slug' => 'retry-distribution-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
            'last_error_message' => 'HTTP 500',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.retry', ['distributionId' => (int) $distribution->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'status' => 'queued',
            'last_error_message' => null,
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class);
    }

    public function test_distribution_jobs_page_can_filter_by_status_and_channel(): void
    {
        $fixtures = $this->taskFixtures();
        $matchingChannel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        $otherChannel = DistributionChannel::query()->create([
            'name' => '其他站点',
            'domain' => 'other.example.com',
            'endpoint_url' => 'https://other.example.com',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '筛选分发文章',
            'slug' => 'filter-distribution-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $matchingChannel->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$matchingChannel->id.'-publish-v1',
            'last_error_message' => 'HTTP 500',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $otherChannel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$otherChannel->id.'-publish-v1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.jobs', [
                'status' => 'failed',
                'channel_id' => (int) $matchingChannel->id,
            ]))
            ->assertOk()
            ->assertSee('官网主站')
            ->assertSee('HTTP 500')
            ->assertDontSee('other.example.com');
    }

    public function test_retryable_distribution_failure_is_requeued_by_policy(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/geoflow-agent/v1/articles' => Http::response(['ok' => false], 500),
        ]);

        $fixtures = $this->taskFixtures();
        $channel = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_test_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        $task = Task::query()->create([
            'name' => '重试来源任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->distributionChannels()->syncWithPivotValues([(int) $channel->id], [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
        ]);
        $article = Article::query()->create([
            'title' => '可重试分发文章',
            'slug' => 'retryable-distribution-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$channel->id.'-publish-v1',
        ]);

        app(ProcessArticleDistributionJob::class, ['distributionId' => (int) $distribution->id])
            ->handle(app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class));

        $distribution->refresh();
        $this->assertSame('queued', $distribution->status);
        $this->assertSame(1, (int) $distribution->attempt_count);
        $this->assertNotNull($distribution->next_retry_at);
        Queue::assertPushed(ProcessArticleDistributionJob::class);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'distribution_admin',
            'password' => 'secret-123',
            'email' => 'distribution-admin@example.com',
            'display_name' => 'Distribution Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{
     *     ai_model: AiModel,
     *     prompt: Prompt,
     *     title_library: TitleLibrary,
     *     category: Category,
     *     author: Author
     * }
     */
    private function taskFixtures(): array
    {
        $aiModel = AiModel::query()->create([
            'name' => '测试模型',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-model',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.com/v1',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '请写 {{title}}',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);

        return [
            'ai_model' => $aiModel,
            'prompt' => $prompt,
            'title_library' => $titleLibrary,
            'category' => $category,
            'author' => $author,
        ];
    }
}
