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
use App\Services\GeoFlow\DistributionHttpClient;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Services\GeoFlow\DistributionSigningService;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Services\GeoFlow\FrontendExperienceInspector;
use App\Services\GeoFlow\TaskDistributionChannelSelector;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\HomepageModuleBuilder;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
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

    public function test_distribution_index_selected_sync_modal_shows_frontend_sync_summary(): void
    {
        DistributionChannel::query()->create([
            'name' => '批量预览渠道',
            'domain' => 'preview.example.com',
            'endpoint_url' => 'https://preview.example.com',
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'homepage_modules' => [
                    [
                        'type' => 'hero',
                        'title' => '批量预览 Hero',
                        'body' => '批量预览正文',
                        'enabled' => true,
                        'sort_order' => 10,
                    ],
                ],
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/preview.jpg',
                        'title' => '批量预览轮播',
                        'link_url' => '/preview',
                        'enabled' => true,
                    ],
                ],
            ],
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.index'))
            ->assertOk()
            ->assertSee('批量预览渠道')
            ->assertSee('custom · default · rewrite')
            ->assertSee('模块 1 · 轮播 1 · 文字广告 0');
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

    public function test_distribution_channel_edit_form_collapses_template_choices_after_two_rows(): void
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

        $channel = DistributionChannel::query()->create([
            'name' => '模板折叠渠道',
            'domain' => 'theme-collapse.example.com',
            'endpoint_url' => 'https://theme-collapse.example.com',
            'template_key' => 'theme-08',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.site_settings.theme.section_title'))
            ->assertSee('name="template_key" value=""', false)
            ->assertSee('name="template_key" value="theme-08"', false)
            ->assertSee(__('admin.distribution.remote_site.template_expand_more', ['count' => 2]));

        $html = (string) $response->getContent();

        $this->assertSame(3, substr_count($html, 'data-distribution-theme-collapsed="true"'));
        $this->assertMatchesRegularExpression('/class="[^"]*hidden[^"]*"[^>]*data-distribution-theme-card[^>]*data-distribution-theme-collapsed="true"[^>]*>\\s*<input[^>]+value="theme-06"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*hidden[^"]*"[^>]*data-distribution-theme-card[^>]*data-distribution-theme-collapsed="true"[^>]*>\\s*<input[^>]+value="theme-08"/s', $html);
    }

    public function test_distribution_channel_edit_custom_text_ads_render_localized_fields(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '自定义广告编辑渠道',
            'domain' => 'custom-ad-edit.example.com',
            'endpoint_url' => 'https://custom-ad-edit.example.com',
            'status' => 'active',
            'channel_config' => [
                'article_text_ad_policy' => [
                    'content_top' => [
                        'mode' => 'custom',
                        'custom_modules' => [
                            [
                                'id' => 'channel-module-1',
                                'name' => '渠道顶部广告',
                                'placement' => 'content_top',
                                'enabled' => true,
                                'sort_order' => 10,
                                'links' => [
                                    [
                                        'text' => '渠道文字链',
                                        'url' => 'https://example.com/landing',
                                        'text_color' => '#2563eb',
                                        'open_new_tab' => true,
                                        'tracking_enabled' => true,
                                        'tracking_param' => 'utm_source=channel',
                                        'enabled' => true,
                                        'sort_order' => 10,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'content_bottom' => [
                        'mode' => 'inherit',
                    ],
                ],
            ],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.site_settings.ads.text_field_name'))
            ->assertSee(__('admin.site_settings.ads.text_link_section'))
            ->assertSee(__('admin.site_settings.ads.text_add_link'))
            ->assertSee('name="article_text_ad_policy[content_top][custom_modules][0][links][0][text]"', false)
            ->assertSee('渠道文字链')
            ->assertDontSee('admin.site_settings.article_detail_ads')
            ->assertDontSee('admin.site_settings.ads.');
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
            ->assertSee('查看同步预览')
            ->assertSee('覆盖新版站点包后')
            ->assertSee(route('admin.distribution.sync-settings.preview', ['channelId' => (int) $channel->id]), false);

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
        $basicSiteSettings = [
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
        ];
        $this->assertSame($basicSiteSettings, array_intersect_key($channel->site_settings, $basicSiteSettings));
        $this->assertArrayHasKey('homepage_style', $channel->site_settings);
        $this->assertArrayHasKey('homepage_modules', $channel->site_settings);
        $this->assertArrayHasKey('home_carousel_slides', $channel->site_settings);
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

    public function test_distribution_channel_frontend_experience_settings_are_saved_to_target_payload(): void
    {
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '前台体验渠道',
            'domain' => 'frontend.example.com',
            'endpoint_url' => 'https://frontend.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '前台体验渠道',
                'domain' => 'frontend.example.com',
                'endpoint_url' => 'https://frontend.example.com',
                'front_mode' => 'static',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '',
                'site_name' => '前台体验站',
                'site_subtitle' => '渠道首页',
                'site_description' => '渠道首页描述',
                'site_keywords' => 'frontend,channel',
                'copyright_info' => '© 2026 前台体验站',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                'homepage_style_json' => json_encode([
                    'accent_color' => '#0f766e',
                    'background_color' => '#ffffff',
                    'surface_color' => '#f8fafc',
                    'text_color' => '#111827',
                    'muted_color' => '#64748b',
                    'container_width' => 'wide',
                    'section_spacing' => 'relaxed',
                    'radius' => 'soft',
                ], JSON_UNESCAPED_UNICODE),
                'homepage_modules_json' => json_encode([
                    [
                        'type' => 'hero',
                        'title' => '渠道 Hero',
                        'subtitle' => 'CHANNEL',
                        'body' => '渠道站点首页主视觉。',
                        'link_text' => '查看文章',
                        'link_url' => '/article/demo',
                        'enabled' => true,
                        'sort_order' => 10,
                    ],
                    [
                        'type' => 'article_collection',
                        'title' => '热门文章',
                        'data_source' => 'hot',
                        'limit' => 3,
                        'enabled' => true,
                        'sort_order' => 20,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'home_carousel_slides_json' => json_encode([
                    [
                        'image_url' => '/storage/channel-hero.jpg',
                        'title' => '渠道轮播',
                        'link_url' => '/article/demo',
                        'enabled' => true,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $channel->refresh();
        $this->assertSame(DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM, $channel->frontendExperienceMode());

        $payload = $channel->targetSiteSettingsPayload();
        $this->assertSame(DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM, $payload['frontend_experience_mode']);
        $this->assertSame('#0f766e', $payload['homepage_style']['accent_color']);
        $this->assertCount(2, $payload['homepage_modules']);
        $this->assertSame('hero', $payload['homepage_modules'][0]['type']);
        $this->assertSame('hot', $payload['homepage_modules'][1]['data_source']);
        $this->assertSame('/storage/channel-hero.jpg', $payload['home_carousel_slides'][0]['image_url']);
    }

    public function test_distribution_channel_inherit_default_mode_uses_default_frontend_snapshot_when_saved(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode([
                [
                    'type' => 'hero',
                    'title' => '默认站 Hero',
                    'body' => '默认站首页正文',
                    'enabled' => true,
                    'sort_order' => 10,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_style'],
            ['setting_value' => json_encode([
                'accent_color' => '#2563eb',
                'background_color' => '#ffffff',
                'surface_color' => '#ffffff',
                'text_color' => '#111827',
                'muted_color' => '#6b7280',
                'container_width' => 'default',
                'section_spacing' => 'normal',
                'radius' => 'soft',
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'home_carousel_slides'],
            ['setting_value' => json_encode([
                [
                    'image_url' => '/storage/default-slide.jpg',
                    'title' => '默认站轮播',
                    'link_url' => '/default',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $channel = DistributionChannel::query()->create([
            'name' => '切换默认站渠道',
            'domain' => 'inherit.example.com',
            'endpoint_url' => 'https://inherit.example.com',
            'channel_type' => 'geoflow_agent',
            'site_settings' => [
                'homepage_modules' => [
                    [
                        'type' => 'hero',
                        'title' => '旧渠道 Hero',
                        'body' => '旧渠道正文',
                        'enabled' => true,
                        'sort_order' => 10,
                    ],
                ],
            ],
            'channel_config' => [
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
            ],
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '切换默认站渠道',
                'domain' => 'inherit.example.com',
                'endpoint_url' => 'https://inherit.example.com',
                'front_mode' => 'static',
                'template_key' => '',
                'status' => 'active',
                'description' => '',
                'site_name' => '切换默认站渠道',
                'site_subtitle' => '',
                'site_description' => '描述',
                'site_keywords' => '',
                'copyright_info' => '© 2026',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_INHERIT_DEFAULT,
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $channel->refresh();
        $payload = $channel->targetSiteSettingsPayload();
        $this->assertSame(DistributionChannel::FRONTEND_EXPERIENCE_INHERIT_DEFAULT, $payload['frontend_experience_mode']);
        $this->assertSame('默认站 Hero', $payload['homepage_modules'][0]['title']);
        $this->assertSame('/storage/default-slide.jpg', $payload['home_carousel_slides'][0]['image_url']);
        SiteSettingsBag::forget();
    }

    public function test_distribution_channel_snapshot_default_mode_keeps_saved_default_frontend_copy(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode([
                [
                    'type' => 'hero',
                    'title' => '快照默认 Hero',
                    'body' => '快照默认正文',
                    'enabled' => true,
                    'sort_order' => 10,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $channel = DistributionChannel::query()->create([
            'name' => '快照默认站渠道',
            'domain' => 'snapshot.example.com',
            'endpoint_url' => 'https://snapshot.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '快照默认站渠道',
                'domain' => 'snapshot.example.com',
                'endpoint_url' => 'https://snapshot.example.com',
                'front_mode' => 'static',
                'template_key' => '',
                'status' => 'active',
                'description' => '',
                'site_name' => '快照默认站渠道',
                'site_subtitle' => '',
                'site_description' => '描述',
                'site_keywords' => '',
                'copyright_info' => '© 2026',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_SNAPSHOT_DEFAULT,
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode([
                [
                    'type' => 'hero',
                    'title' => '后续默认站 Hero',
                    'body' => '后续默认正文',
                    'enabled' => true,
                    'sort_order' => 10,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $channel->refresh();
        $payload = $channel->targetSiteSettingsPayload();
        $this->assertSame(DistributionChannel::FRONTEND_EXPERIENCE_SNAPSHOT_DEFAULT, $payload['frontend_experience_mode']);
        $this->assertSame('快照默认 Hero', $payload['homepage_modules'][0]['title']);
        SiteSettingsBag::forget();
    }

    public function test_frontend_experience_inspector_command_reports_channel_capabilities(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => '盘点渠道',
            'domain' => 'inspect.example.com',
            'endpoint_url' => 'https://inspect.example.com',
            'channel_type' => 'geoflow_agent',
            'channel_config' => [
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_INHERIT_DEFAULT,
            ],
            'status' => 'active',
        ]);

        $report = app(FrontendExperienceInspector::class)->inspect($channel->fresh(), true);
        $this->assertSame(DistributionChannel::FRONTEND_EXPERIENCE_INHERIT_DEFAULT, $report['channel']['frontend_experience_mode']);
        $this->assertContains('hero', $report['target_package']['supported_modules']);
        $this->assertSame('not_checked', $report['remote_target']['status']);
        $this->assertArrayHasKey('sync_summary', $report['channel']);
        $this->assertSame('missing_secret', app(FrontendExperienceInspector::class)->inspect($channel->fresh(), true, true)['remote_target']['status']);

        $this->artisan('geoflow:frontend-experience', [
            'channel' => (string) $channel->id,
            '--json' => true,
        ])
            ->expectsOutputToContain('"target_package"')
            ->assertExitCode(0);

        $this->artisan('geoflow:frontend-experience', [
            'channel' => (string) $channel->id,
        ])
            ->expectsOutputToContain('Sync summary: modules=')
            ->expectsOutputToContain('Remote capabilities: not_checked')
            ->assertExitCode(0);

        $this->artisan('geoflow:frontend-experience', [
            'channel' => (string) $channel->id,
            '--live-remote' => true,
        ])
            ->expectsOutputToContain('Remote capabilities: missing_secret')
            ->assertExitCode(0);
    }

    public function test_distribution_channel_edit_shows_frontend_experience_summary_and_remote_capabilities(): void
    {
        Http::fake();

        $channel = DistributionChannel::query()->create([
            'name' => '前台摘要渠道',
            'domain' => 'frontend.example.com',
            'endpoint_url' => 'https://frontend.example.com',
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'static',
            'template_key' => 'default',
            'channel_config' => [
                DistributionChannel::FRONTEND_CAPABILITIES_CACHE_KEY => [
                    'status' => 'ok',
                    'checked_at' => '2026-06-29T08:00:00+00:00',
                    'message' => 'cached ok',
                    'reachable' => true,
                    'capability_version' => '1.2',
                    'package_version' => '2026.06',
                    'active_theme' => 'remote-theme',
                    'front_mode' => 'rewrite',
                    'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                    'supported_modules' => HomepageModuleBuilder::TYPES,
                    'supported_routes' => ['/', '/article/{slug}', '/geoflow-agent/v1/frontend-capabilities'],
                    'supports_homepage_style' => true,
                    'supports_home_carousel_slides' => true,
                    'supports_article_text_ads' => true,
                    'supports_static_generation' => true,
                ],
            ],
            'site_settings' => [
                'homepage_style' => [
                    'accent_color' => '#0f766e',
                    'background_color' => '#ffffff',
                    'surface_color' => '#f8fafc',
                    'text_color' => '#111827',
                    'muted_color' => '#64748b',
                    'container_width' => 'wide',
                    'section_spacing' => 'relaxed',
                    'radius' => 'soft',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'hero',
                        'title' => '渠道 Hero',
                        'body' => '渠道站点首页主视觉。',
                        'enabled' => true,
                        'sort_order' => 10,
                    ],
                ],
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/channel-hero.jpg',
                        'title' => '渠道轮播',
                        'link_url' => '/article/demo',
                        'enabled' => true,
                    ],
                ],
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_frontend_summary',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_frontend_summary_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('前台体验')
            ->assertSee('远端能力状态：已检查')
            ->assertSee('同步前差异摘要')
            ->assertSee('首页模块')
            ->assertSee('文字广告')
            ->assertSee('目标包 2026.06')
            ->assertSee('最近检查 2026-06-29T08:00:00+00:00')
            ->assertSee('remote-theme')
            ->assertSee('远端 front_mode 为 rewrite');

        Http::assertNothingSent();
    }

    public function test_frontend_experience_inspector_reports_remote_capability_states(): void
    {
        $inspector = app(FrontendExperienceInspector::class);

        Http::fake([
            'https://ok.example.com/geoflow-agent/v1/frontend-capabilities' => Http::response([
                'capability_version' => '1.1',
                'package_version' => 'ok-package',
                'active_theme' => 'default',
                'front_mode' => 'static',
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_INHERIT_DEFAULT,
                'supported_modules' => ['hero', 'rich_text'],
                'supported_routes' => ['/', '/geoflow-agent/v1/frontend-capabilities'],
                'supports_homepage_style' => true,
                'supports_home_carousel_slides' => true,
                'supports_article_text_ads' => true,
                'supports_static_generation' => true,
            ]),
            'https://failed.example.com/geoflow-agent/v1/frontend-capabilities' => Http::response(['ok' => false], 500),
            'https://old.example.com/geoflow-agent/v1/frontend-capabilities' => Http::response('<h1>Not Found</h1>', 404),
            'https://old.example.com/index.php/geoflow-agent/v1/frontend-capabilities' => Http::response('<h1>Not Found</h1>', 404),
            'https://down.example.com/geoflow-agent/v1/frontend-capabilities' => Http::failedConnection('connection refused'),
            'https://down.example.com/index.php/geoflow-agent/v1/frontend-capabilities' => Http::failedConnection('connection refused'),
        ]);

        $ok = $this->frontendCapabilityChannel('ok.example.com');
        $failed = $this->frontendCapabilityChannel('failed.example.com');
        $old = $this->frontendCapabilityChannel('old.example.com');
        $down = $this->frontendCapabilityChannel('down.example.com');
        $missingSecret = DistributionChannel::query()->create([
            'name' => '缺少密钥',
            'domain' => 'missing.example.com',
            'endpoint_url' => 'https://missing.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);

        $this->assertSame('ok', $inspector->inspect($ok, true, true)['remote_target']['status']);
        $this->assertSame('unavailable', $inspector->inspect($failed, true, true)['remote_target']['status']);
        $this->assertSame('unsupported_or_not_found', $inspector->inspect($old, true, true)['remote_target']['status']);
        $this->assertSame('unavailable', $inspector->inspect($down, true, true)['remote_target']['status']);
        $this->assertSame('missing_secret', $inspector->inspect($missingSecret, true, true)['remote_target']['status']);
    }

    public function test_admin_can_refresh_frontend_capabilities_cache_for_success_and_failure_states(): void
    {
        $admin = $this->admin();

        Http::fake([
            'https://refresh-ok.example.com/geoflow-agent/v1/frontend-capabilities' => Http::response([
                'capability_version' => '1.2',
                'package_version' => 'refresh-package',
                'active_theme' => 'default',
                'front_mode' => 'static',
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                'supported_modules' => HomepageModuleBuilder::TYPES,
                'supported_routes' => ['/', '/article/{slug}', '/geoflow-agent/v1/frontend-capabilities'],
                'supports_homepage_style' => true,
                'supports_home_carousel_slides' => true,
                'supports_article_text_ads' => true,
                'supports_static_generation' => true,
                'current_settings' => [
                    'active_theme' => 'default',
                    'front_mode' => 'static',
                    'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                ],
            ]),
            'https://refresh-old.example.com/geoflow-agent/v1/frontend-capabilities' => Http::response('<h1>Not Found</h1>', 404),
            'https://refresh-old.example.com/index.php/geoflow-agent/v1/frontend-capabilities' => Http::response('<h1>Not Found</h1>', 404),
            'https://refresh-down.example.com/geoflow-agent/v1/frontend-capabilities' => Http::failedConnection('connection refused'),
        ]);

        $ok = $this->frontendCapabilityChannel('refresh-ok.example.com');
        $old = $this->frontendCapabilityChannel('refresh-old.example.com');
        $down = $this->frontendCapabilityChannel('refresh-down.example.com');
        $missingSecret = DistributionChannel::query()->create([
            'name' => '刷新缺少密钥',
            'domain' => 'refresh-missing.example.com',
            'endpoint_url' => 'https://refresh-missing.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $ok->id]))
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $old->id]))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $down->id]))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.frontend-capabilities.refresh', ['channelId' => (int) $missingSecret->id]))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame('ok', $ok->fresh()->frontendCapabilitiesCache()['status']);
        $this->assertSame('1.2', $ok->fresh()->frontendCapabilitiesCache()['capability_version']);
        $this->assertSame('refresh-package', $ok->fresh()->frontendCapabilitiesCache()['package_version']);
        $this->assertSame('unsupported_or_not_found', $old->fresh()->frontendCapabilitiesCache()['status']);
        $this->assertSame('unavailable', $down->fresh()->frontendCapabilitiesCache()['status']);
        $this->assertSame('missing_secret', $missingSecret->fresh()->frontendCapabilitiesCache()['status']);
    }

    public function test_sync_settings_preview_soft_blocks_risky_channel_until_confirmed(): void
    {
        $admin = $this->admin();

        Http::fake([
            'https://preview-risk.example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $channel = DistributionChannel::query()->create([
            'name' => '预览风险渠道',
            'domain' => 'preview-risk.example.com',
            'endpoint_url' => 'https://preview-risk.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_preview_risk',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_preview_risk_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.sync-settings.preview', ['channelId' => (int) $channel->id]))
            ->assertSessionHasErrors();

        Http::assertNothingSent();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.sync-settings.preview', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('前台体验同步预览')
            ->assertSee('需要确认后同步')
            ->assertSee('not_checked')
            ->assertSee('即将发送的 settings JSON')
            ->assertSee('frontend_sync_confirmed', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]), [
                'frontend_sync_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('message', __('admin.distribution.message.settings_synced'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://preview-risk.example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update'));
    }

    public function test_sync_settings_preview_pages_cover_all_and_selected_channels(): void
    {
        $admin = $this->admin();

        $first = DistributionChannel::query()->create([
            'name' => '预览一号站',
            'domain' => 'preview-one.example.com',
            'endpoint_url' => 'https://preview-one.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        $second = DistributionChannel::query()->create([
            'name' => '预览二号站',
            'domain' => 'preview-two.example.com',
            'endpoint_url' => 'https://preview-two.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannel::query()->create([
            'name' => '预览暂停站',
            'domain' => 'preview-paused.example.com',
            'endpoint_url' => 'https://preview-paused.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'paused',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.sync-settings-all.preview'))
            ->assertOk()
            ->assertSee('预览一号站')
            ->assertSee('预览二号站')
            ->assertDontSee('预览暂停站')
            ->assertSee('确认并同步');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.sync-settings-selected.preview'), [
                'channel_ids' => [(int) $second->id],
            ])
            ->assertOk()
            ->assertDontSee('预览一号站')
            ->assertSee('预览二号站')
            ->assertSee('name="channel_ids[]"', false);

        $this->assertNotNull($first->id);
    }

    public function test_frontend_experience_command_defaults_to_cache_and_live_remote_does_not_persist_cache(): void
    {
        $channel = $this->frontendCapabilityChannel('live-cache.example.com');
        $channel->fillFrontendCapabilitiesCache([
            'status' => 'ok',
            'checked_at' => now()->toISOString(),
            'message' => 'cached',
            'reachable' => true,
            'capability_version' => '1.2',
            'package_version' => 'cached-package',
            'active_theme' => 'cached-theme',
            'front_mode' => 'static',
            'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
            'supported_modules' => HomepageModuleBuilder::TYPES,
            'supported_routes' => ['/', '/geoflow-agent/v1/frontend-capabilities'],
            'supports_homepage_style' => true,
            'supports_home_carousel_slides' => true,
            'supports_article_text_ads' => true,
            'supports_static_generation' => true,
        ])->save();

        Http::fake([
            'https://live-cache.example.com/geoflow-agent/v1/frontend-capabilities' => Http::response([
                'capability_version' => '1.2',
                'package_version' => 'live-package',
                'active_theme' => 'live-theme',
                'front_mode' => 'rewrite',
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                'supported_modules' => ['hero'],
                'supported_routes' => ['/'],
                'supports_homepage_style' => true,
                'supports_home_carousel_slides' => true,
                'supports_article_text_ads' => true,
                'supports_static_generation' => true,
            ]),
        ]);

        $this->artisan('geoflow:frontend-experience', [
            'channel' => (string) $channel->id,
            '--json' => true,
        ])
            ->expectsOutputToContain('cached-package')
            ->assertExitCode(0);
        Http::assertNothingSent();

        $this->artisan('geoflow:frontend-experience', [
            'channel' => (string) $channel->id,
            '--json' => true,
            '--live-remote' => true,
        ])
            ->expectsOutputToContain('live-package')
            ->assertExitCode(0);

        $this->assertSame('cached-package', $channel->fresh()->frontendCapabilitiesCache()['package_version']);
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
            'channel_config' => [
                DistributionChannel::FRONTEND_CAPABILITIES_CACHE_KEY => [
                    'status' => 'ok',
                    'checked_at' => now()->toISOString(),
                    'message' => 'ok',
                    'reachable' => true,
                    'capability_version' => '1.2',
                    'package_version' => 'test-package',
                    'active_theme' => 'netease-news-20260507',
                    'front_mode' => 'static',
                    'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
                    'supported_modules' => HomepageModuleBuilder::TYPES,
                    'supported_routes' => ['/', '/article/{slug}', '/geoflow-agent/v1/frontend-capabilities'],
                    'supports_homepage_style' => true,
                    'supports_home_carousel_slides' => true,
                    'supports_article_text_ads' => true,
                    'supports_static_generation' => true,
                ],
            ],
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

        $this->assertSame('test-package', $channel->fresh()->frontendCapabilitiesCache()['package_version']);

        $this->assertDatabaseHas('distribution_logs', [
            'distribution_channel_id' => (int) $channel->id,
            'event' => 'site.settings.synced',
        ]);
    }

    public function test_update_distribution_channel_saves_but_does_not_auto_sync_when_frontend_preview_needs_confirmation(): void
    {
        Http::fake([
            'https://needs-preview.example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '待预览渠道',
            'domain' => 'needs-preview.example.com',
            'endpoint_url' => 'https://needs-preview.example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_update_preview',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_update_preview_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), [
                'name' => '待预览渠道已保存',
                'domain' => 'needs-preview.example.com',
                'endpoint_url' => 'https://needs-preview.example.com',
                'front_mode' => 'static',
                'template_key' => 'default',
                'status' => 'active',
                'description' => '保存但不自动同步',
                'site_name' => '待预览门户',
                'site_subtitle' => '',
                'site_description' => '待预览描述',
                'site_keywords' => '',
                'copyright_info' => '© 2026 待预览门户',
                'site_logo' => '',
                'site_favicon' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHas('message', __('admin.distribution.message.updated'))
            ->assertSessionHasErrors();

        $this->assertSame('待预览渠道已保存', $channel->fresh()->name);
        Http::assertNothingSent();
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
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]), [
                'frontend_sync_confirmed' => '1',
            ])
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

    public function test_admin_can_sync_all_active_geoflow_agent_channel_settings(): void
    {
        Queue::fake();
        Http::fake([
            'https://one.example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
            'https://two.example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
        ]);

        $fixtures = $this->taskFixtures();
        $first = DistributionChannel::query()->create([
            'name' => '一号站',
            'domain' => 'one.example.com',
            'endpoint_url' => 'https://one.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $first->id,
            'key_id' => 'gfk_sync_all_one',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_all_one_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);
        $article = Article::query()->create([
            'title' => '需要刷新 SEO 的文章',
            'slug' => 'refresh-seo-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $fixtures['category']->id,
            'author_id' => (int) $fixtures['author']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $first->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_url' => 'https://one.example.com/article/refresh-seo-article',
            'idempotency_key' => 'old-sync-all-key',
        ]);

        $second = DistributionChannel::query()->create([
            'name' => '二号站',
            'domain' => 'two.example.com',
            'endpoint_url' => 'https://two.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $second->id,
            'key_id' => 'gfk_sync_all_two',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_all_two_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $wordpress = DistributionChannel::query()->create([
            'name' => 'WordPress',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $wordpress->id,
            'key_id' => 'gfk_sync_all_wp',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_all_wp_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $paused = DistributionChannel::query()->create([
            'name' => '暂停站',
            'domain' => 'paused.example.com',
            'endpoint_url' => 'https://paused.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'paused',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $paused->id,
            'key_id' => 'gfk_sync_all_paused',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_all_paused_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.sync-settings-all'), [
                'frontend_sync_confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('message', __('admin.distribution.message.settings_synced_all', [
                'success' => 2,
                'failed' => 0,
                'refresh' => 1,
            ]));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://one.example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://two.example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update'));

        $this->assertDatabaseHas('distribution_logs', [
            'distribution_channel_id' => (int) $first->id,
            'event' => 'site.settings.synced',
        ]);
        $this->assertDatabaseHas('distribution_logs', [
            'distribution_channel_id' => (int) $second->id,
            'event' => 'site.settings.synced',
        ]);
        $this->assertDatabaseHas('distribution_logs', [
            'distribution_channel_id' => (int) $first->id,
            'event' => 'target.content_refresh_queued',
        ]);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $first->id,
            'action' => 'update',
            'status' => 'queued',
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_admin_can_sync_selected_active_geoflow_agent_channel_settings(): void
    {
        Queue::fake();
        Http::fake([
            'https://selected-one.example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
            'https://selected-two.example.com/geoflow-agent/v1/site-settings' => Http::response([
                'ok' => true,
                'updated' => true,
            ]),
            '*' => Http::response(['ok' => false], 500),
        ]);

        $first = DistributionChannel::query()->create([
            'name' => '所选一号站',
            'domain' => 'selected-one.example.com',
            'endpoint_url' => 'https://selected-one.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $first->id,
            'key_id' => 'gfk_sync_selected_one',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_selected_one_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $second = DistributionChannel::query()->create([
            'name' => '所选二号站',
            'domain' => 'selected-two.example.com',
            'endpoint_url' => 'https://selected-two.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $second->id,
            'key_id' => 'gfk_sync_selected_two',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_selected_two_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $unselected = DistributionChannel::query()->create([
            'name' => '未选择站点',
            'domain' => 'unselected.example.com',
            'endpoint_url' => 'https://unselected.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $unselected->id,
            'key_id' => 'gfk_sync_selected_unselected',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_sync_selected_unselected_secret'),
            'status' => 'active',
            'scopes' => ['site.settings.update'],
        ]);

        $wordpress = DistributionChannel::query()->create([
            'name' => 'WordPress',
            'domain' => 'wp-selected.example.com',
            'endpoint_url' => 'https://wp-selected.example.com',
            'channel_type' => 'wordpress_rest',
            'status' => 'active',
        ]);

        $paused = DistributionChannel::query()->create([
            'name' => '暂停站',
            'domain' => 'paused-selected.example.com',
            'endpoint_url' => 'https://paused-selected.example.com',
            'channel_type' => 'geoflow_agent',
            'status' => 'paused',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.sync-settings-selected'), [
                'frontend_sync_confirmed' => '1',
                'channel_ids' => [
                    (int) $first->id,
                    (int) $second->id,
                    (int) $unselected->id + 9999,
                    (int) $wordpress->id,
                    (int) $paused->id,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('message', __('admin.distribution.message.settings_synced_selected', [
                'success' => 2,
                'failed' => 0,
                'refresh' => 0,
            ]));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://selected-one.example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://selected-two.example.com/geoflow-agent/v1/site-settings'
            && $request->hasHeader('X-GEOFlow-Event', 'site.settings.update'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'unselected.example.com'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'wp-selected.example.com'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'paused-selected.example.com'));
        Queue::assertNotPushed(ProcessArticleDistributionJob::class);
    }

    public function test_admin_must_select_at_least_one_channel_for_selected_settings_sync(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.sync-settings-selected'), [
                'channel_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();
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
                'homepage_style' => [
                    'accent_color' => '#0f766e',
                    'background_color' => '#ffffff',
                    'surface_color' => '#f8fafc',
                    'text_color' => '#111827',
                    'muted_color' => '#64748b',
                    'container_width' => 'wide',
                    'section_spacing' => 'relaxed',
                    'radius' => 'soft',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'hero',
                        'title' => 'Package Hero',
                        'body' => 'Package homepage module.',
                        'enabled' => true,
                        'sort_order' => 10,
                    ],
                ],
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/package-hero.jpg',
                        'title' => 'Package Slide',
                        'link_url' => '/article/package',
                        'enabled' => true,
                    ],
                ],
            ],
            'channel_config' => [
                'frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM,
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
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/slides/package.jpg',
                        'title' => 'Package hero slide',
                        'link_url' => '/package-offer',
                        'enabled' => true,
                    ],
                ],
                'homepage_modules' => [
                    [
                        'type' => 'hero',
                        'layout' => 'split',
                        'title' => 'Remote Hero Module',
                        'subtitle' => 'Remote hero subtitle',
                        'body' => 'Remote hero body',
                        'image_url' => '/storage/modules/hero.jpg',
                        'link_text' => 'Hero CTA',
                        'link_url' => '/hero-cta',
                        'enabled' => true,
                        'sort_order' => 10,
                    ],
                    [
                        'type' => 'rich_text',
                        'title' => 'Remote Rich Text',
                        'body' => 'Remote rich text body',
                        'enabled' => true,
                        'sort_order' => 20,
                    ],
                    [
                        'type' => 'image_band',
                        'title' => 'Remote Image Band',
                        'body' => 'Remote image band body',
                        'image_url' => '/storage/modules/image-band.jpg',
                        'link_text' => 'Image CTA',
                        'link_url' => '/image-band',
                        'enabled' => true,
                        'sort_order' => 30,
                    ],
                    [
                        'type' => 'metric_band',
                        'title' => 'Remote Metrics',
                        'body' => "Metric One|42|units\nMetric Two|88|score",
                        'enabled' => true,
                        'sort_order' => 40,
                    ],
                    [
                        'type' => 'chart_band',
                        'title' => 'Remote Chart',
                        'body' => "Chart A|64\nChart B|92",
                        'enabled' => true,
                        'sort_order' => 50,
                    ],
                    [
                        'type' => 'feature_grid',
                        'title' => 'Remote Features',
                        'body' => "Feature One|Feature one body|/feature-one\nFeature Two|Feature two body|/feature-two",
                        'enabled' => true,
                        'sort_order' => 60,
                    ],
                    [
                        'type' => 'article_collection',
                        'title' => 'Remote Articles',
                        'data_source' => 'featured',
                        'limit' => 3,
                        'enabled' => true,
                        'sort_order' => 70,
                    ],
                    [
                        'type' => 'cta_band',
                        'title' => 'Remote CTA Band',
                        'body' => 'Remote CTA body',
                        'link_text' => 'CTA Link',
                        'link_url' => '/cta',
                        'enabled' => true,
                        'sort_order' => 80,
                    ],
                    [
                        'type' => 'custom_html',
                        'title' => 'Remote Custom HTML',
                        'custom_html' => '<section><h3>Remote custom heading</h3><p>Remote custom body</p></section>',
                        'enabled' => true,
                        'sort_order' => 90,
                    ],
                ],
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
        $this->assertStringContainsString("'package_version' => '".config('geoflow.app_version')."'", $config);
        $this->assertStringContainsString("'homepage_style' =>", $config);
        $this->assertStringContainsString("'homepage_modules' =>", $config);
        $this->assertStringContainsString("'home_carousel_slides' =>", $config);
        $this->assertStringContainsString("'frontend_experience_mode' => 'custom'", $config);
        $this->assertStringContainsString("'article_text_ads' =>", $config);
        $this->assertStringContainsString("'Package CTA'", $config);

        $rootIndex = (string) $zip->getFromName('index.php');
        $this->assertStringContainsString("require __DIR__.'/public/index.php';", $rootIndex);

        $staticIndex = (string) $zip->getFromName('index.html');
        $this->assertStringContainsString('远程门户', $staticIndex);
        $this->assertStringContainsString('<title>首页 - 远程门户</title>', $staticIndex);
        $this->assertStringContainsString('<meta name="description" content="远程站点描述 - 远程门户">', $staticIndex);
        $this->assertStringContainsString('<meta name="keywords" content="geo,remote">', $staticIndex);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/">', $staticIndex);
        $this->assertStringContainsString('<meta property="og:title" content="首页 - 远程门户">', $staticIndex);
        $this->assertStringContainsString('<meta property="og:description" content="远程站点描述 - 远程门户">', $staticIndex);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $staticIndex);
        $this->assertStringContainsString('<meta property="og:url" content="https://example.com/">', $staticIndex);
        $this->assertStringContainsString('<meta property="og:site_name" content="远程门户">', $staticIndex);
        $this->assertStringContainsString('<link rel="icon" href="https://example.com/favicon.ico">', $staticIndex);
        $this->assertStringContainsString('class="homepage-carousel"', $staticIndex);
        $this->assertStringContainsString('/storage/slides/package.jpg', $staticIndex);
        $this->assertStringContainsString('Package hero slide', $staticIndex);
        $this->assertStringContainsString('class="homepage-modules"', $staticIndex);
        $this->assertStringContainsString('homepage-hero', $staticIndex);
        $this->assertStringContainsString('Remote Hero Module', $staticIndex);
        $this->assertStringContainsString('/storage/modules/hero.jpg', $staticIndex);
        $this->assertStringContainsString('homepage-rich_text', $staticIndex);
        $this->assertStringContainsString('Remote Rich Text', $staticIndex);
        $this->assertStringContainsString('homepage-image_band', $staticIndex);
        $this->assertStringContainsString('/storage/modules/image-band.jpg', $staticIndex);
        $this->assertStringContainsString('homepage-metric_band', $staticIndex);
        $this->assertStringContainsString('Metric One', $staticIndex);
        $this->assertStringContainsString('homepage-chart_band', $staticIndex);
        $this->assertStringContainsString('Chart A', $staticIndex);
        $this->assertStringContainsString('homepage-feature_grid', $staticIndex);
        $this->assertStringContainsString('Feature One', $staticIndex);
        $this->assertStringContainsString('homepage-article_collection', $staticIndex);
        $this->assertStringContainsString('homepage-article-grid', $staticIndex);
        $this->assertStringContainsString('homepage-cta_band', $staticIndex);
        $this->assertStringContainsString('Remote CTA Band', $staticIndex);
        $this->assertStringContainsString('homepage-custom_html', $staticIndex);
        $this->assertStringContainsString('Remote custom heading', $staticIndex);
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
        $frontController = (string) $zip->getFromName('public/index.php');
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
        $this->assertStringContainsString('.homepage-carousel', $siteCss);
        $this->assertStringContainsString('.homepage-modules', $siteCss);
        $this->assertStringContainsString('function renderHomeCarouselSlides', $frontController);
        $this->assertStringContainsString('function renderHomepageModules', $frontController);
        $this->assertStringContainsString('function renderHomepageModule', $frontController);
        $this->assertStringContainsString("'capability_version' => '1.2'", $frontController);
        $this->assertStringContainsString("'package_version' => (string) (\$config['package_version'] ?? '')", $frontController);
        $this->assertStringContainsString("'current_settings' => [", $frontController);
        $this->assertStringContainsString("'homepage_modules_count' => count(\$homepageModules)", $frontController);
        $this->assertStringContainsString("'home_carousel_slides_count' => count(\$carouselSlides)", $frontController);
        $this->assertStringContainsString("'article_text_ads_count' => count(\$articleTextAds)", $frontController);
        $this->assertStringContainsString("'metric_band'", $frontController);
        $this->assertStringContainsString("'chart_band'", $frontController);
        $this->assertStringContainsString("'feature_grid'", $frontController);
        $this->assertStringContainsString("'article_collection'", $frontController);
        $this->assertStringContainsString("'custom_html'", $frontController);
        $this->assertStringContainsString('/geoflow-agent/v1/frontend-capabilities', $frontController);
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
        $this->assertStringContainsString('function articleMetaDescription', $frontController);
        $this->assertStringContainsString('function articleMetaKeywords', $frontController);
        $this->assertStringContainsString('function pageSeoPayload', $frontController);
        $this->assertStringContainsString('$pageTitle = $isArticle', $frontController);
        $this->assertStringContainsString('$description = $isArticle && $hasMetaDescription && $metaDescription !== \'\'', $frontController);
        $this->assertStringContainsString('og:site_name', $frontController);
        $this->assertStringContainsString('pageHeader($config, $title, [', $frontController);
        $this->assertStringContainsString("array_key_exists('keywords', \$pageMeta)", $frontController);
        $this->assertStringContainsString("'canonical_url' => \$articleUrl", $frontController);
        $this->assertStringContainsString("'og_type' => 'article'", $frontController);
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
        $this->assertStringContainsString('function hasHomepageExperience', $frontController);
        $this->assertStringContainsString("! hasHomepageExperience(\$settings) && themeClass(\$settings) === 'target-theme-fashion'", $frontController);
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
        $this->assertStringContainsString('"description"=>$articleDescription', $frontController);
        $this->assertStringContainsString('"mainEntityOfPage"=>$articleUrl', $frontController);
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

    public function test_target_site_package_runtime_capabilities_settings_sync_and_homepage_rendering(): void
    {
        $port = $this->freeTcpPort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $extractPath = sys_get_temp_dir().'/geoflow-runtime-'.uniqid();

        $channel = DistributionChannel::query()->create([
            'name' => 'Runtime Target',
            'domain' => '127.0.0.1',
            'endpoint_url' => $baseUrl,
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'static',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Runtime Portal',
                'site_description' => 'Runtime target package test',
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/runtime-slide.jpg',
                        'title' => 'Runtime slide',
                        'link_url' => '/',
                        'enabled' => true,
                    ],
                ],
                'homepage_modules' => $this->runtimeHomepageModules(),
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_runtime',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_runtime_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities', 'site.settings.update'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_runtime', 'gfsec_runtime_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        $staticIndex = (string) file_get_contents($extractPath.'/index.html');
        $this->assertStringContainsString('homepage-hero', $staticIndex);
        $this->assertStringContainsString('homepage-custom_html', $staticIndex);
        $this->assertStringContainsString('Runtime slide', $staticIndex);

        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $extractPath.'/public'], $extractPath);
        $server->start();

        try {
            $this->waitForHttpServer($baseUrl);

            $httpClient = app(DistributionHttpClient::class);
            $capabilities = $httpClient->frontendCapabilities($channel->fresh());
            $this->assertSame('1.2', $capabilities['capability_version']);
            $this->assertSame(9, (int) ($capabilities['current_settings']['homepage_modules_count'] ?? 0));
            $this->assertSame(1, (int) ($capabilities['current_settings']['home_carousel_slides_count'] ?? 0));
            $this->assertContains('custom_html', $capabilities['current_settings']['homepage_module_types'] ?? []);

            $syncResult = $httpClient->syncSiteSettings($channel->fresh());
            $this->assertTrue((bool) ($syncResult['updated'] ?? false));

            $runtimeHome = Http::timeout(3)->get($baseUrl.'/')->body();
            foreach ([
                'homepage-hero',
                'homepage-rich_text',
                'homepage-image_band',
                'homepage-metric_band',
                'homepage-chart_band',
                'homepage-feature_grid',
                'homepage-article_collection',
                'homepage-cta_band',
                'homepage-custom_html',
            ] as $moduleClass) {
                $this->assertStringContainsString($moduleClass, $runtimeHome);
            }
            $this->assertStringContainsString('Runtime Custom Heading', $runtimeHome);
        } finally {
            $server->stop(0);
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
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

        $staticIndex = (string) $zip->getFromName('index.html');
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/geoflow-target-site/">', $staticIndex);
        $this->assertStringContainsString('<meta property="og:url" content="https://example.com/geoflow-target-site/">', $staticIndex);
        $this->assertStringNotContainsString('https://example.com/geoflow-target-site/index.php/', $staticIndex);

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
        $this->assertStringNotContainsString('foreach (array_slice(siteCategories($config), 0, 7)', $frontController);
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
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]), [
                'frontend_sync_confirmed' => '1',
            ])
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
            ->post(route('admin.distribution.sync-settings', ['channelId' => (int) $channel->id]), [
                'frontend_sync_confirmed' => '1',
            ])
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
            ->assertSee('仅发布到本站')
            ->assertSee(__('admin.task_create.distribution.strategy_broadcast'))
            ->assertSee(__('admin.task_create.distribution.strategy_round_robin'))
            ->assertSee(__('admin.task_create.distribution.strategy_random_balanced'))
            ->assertSee(__('admin.task_create.button.distribution_channel_select_all'));
    }

    public function test_task_creation_persists_selected_distribution_channels(): void
    {
        $fixtures = $this->taskFixtures();
        $channelOne = DistributionChannel::query()->create([
            'name' => '官网主站',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'template_key' => 'default',
            'status' => 'active',
        ]);
        $channelTwo = DistributionChannel::query()->create([
            'name' => '备用站点',
            'domain' => 'backup.example.com',
            'endpoint_url' => 'https://backup.example.com',
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
                'distribution_strategy' => TaskDistributionChannelSelector::STRATEGY_ROUND_ROBIN,
                'distribution_channel_ids' => [(string) $channelTwo->id, (string) $channelOne->id, (string) $channelTwo->id],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '分发任务')->firstOrFail();
        $this->assertSame('distribution_only', (string) $task->publish_scope);
        $this->assertSame(TaskDistributionChannelSelector::STRATEGY_ROUND_ROBIN, (string) $task->distribution_strategy);
        $this->assertSame(2, $task->distributionChannels()->count());
        $this->assertDatabaseHas('task_distribution_channels', [
            'task_id' => (int) $task->id,
            'distribution_channel_id' => (int) $channelTwo->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('task_distribution_channels', [
            'task_id' => (int) $task->id,
            'distribution_channel_id' => (int) $channelOne->id,
            'sort_order' => 1,
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

    public function test_broadcast_distribution_backfills_newly_selected_channels_for_existing_article(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channelOne = DistributionChannel::query()->create([
            'name' => '广播站点 1',
            'domain' => 'broadcast-1.example.com',
            'endpoint_url' => 'https://broadcast-1.example.com',
            'status' => 'active',
        ]);
        $channelTwo = DistributionChannel::query()->create([
            'name' => '广播站点 2',
            'domain' => 'broadcast-2.example.com',
            'endpoint_url' => 'https://broadcast-2.example.com',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '广播补发任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'distribution_strategy' => TaskDistributionChannelSelector::STRATEGY_BROADCAST,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $article = Article::query()->create([
            'title' => '广播补发文章',
            'slug' => 'broadcast-backfill-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->syncTaskChannels($task, [(int) $channelOne->id]);
        $orchestrator->enqueueForArticle($article);

        $orchestrator->syncTaskChannels($task->fresh(), [(int) $channelOne->id, (int) $channelTwo->id]);
        $orchestrator->enqueueForArticle($article);

        $this->assertSame(
            [(int) $channelOne->id, (int) $channelTwo->id],
            ArticleDistribution::query()
                ->where('article_id', (int) $article->id)
                ->orderBy('distribution_channel_id')
                ->pluck('distribution_channel_id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );
    }

    public function test_round_robin_distribution_sends_each_article_to_one_channel_in_order(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channels = collect(range(1, 3))->map(fn (int $index): DistributionChannel => DistributionChannel::query()->create([
            'name' => '轮询站点 '.$index,
            'domain' => 'round-robin-'.$index.'.example.com',
            'endpoint_url' => 'https://round-robin-'.$index.'.example.com',
            'status' => 'active',
        ]));
        $task = Task::query()->create([
            'name' => '轮询分发任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'distribution_strategy' => TaskDistributionChannelSelector::STRATEGY_ROUND_ROBIN,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->distributionChannels()->sync($channels->values()->mapWithKeys(
            static fn (DistributionChannel $channel, int $index): array => [(int) $channel->id => ['sort_order' => $index]]
        )->all());

        $expectedChannelIds = [
            (int) $channels[0]->id,
            (int) $channels[1]->id,
            (int) $channels[2]->id,
            (int) $channels[0]->id,
        ];

        foreach (range(1, 4) as $index) {
            $article = Article::query()->create([
                'title' => '轮询文章 '.$index,
                'slug' => 'round-robin-article-'.$index,
                'excerpt' => '摘要',
                'content' => '正文',
                'category_id' => $fixtures['category']->id,
                'author_id' => $fixtures['author']->id,
                'task_id' => (int) $task->id,
                'status' => 'published',
                'review_status' => 'approved',
                'published_at' => now(),
            ]);

            app(DistributionOrchestrator::class)->enqueueForArticle($article);

            $this->assertSame(
                [$expectedChannelIds[$index - 1]],
                ArticleDistribution::query()
                    ->where('article_id', (int) $article->id)
                    ->pluck('distribution_channel_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all()
            );
        }

        $this->assertSame(4, (int) $task->fresh()->distribution_cursor);
    }

    public function test_random_balanced_distribution_spreads_articles_across_selected_channels(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channels = collect(range(1, 3))->map(fn (int $index): DistributionChannel => DistributionChannel::query()->create([
            'name' => '均衡站点 '.$index,
            'domain' => 'balanced-'.$index.'.example.com',
            'endpoint_url' => 'https://balanced-'.$index.'.example.com',
            'status' => 'active',
        ]));
        $task = Task::query()->create([
            'name' => '随机均衡分发任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'distribution_strategy' => TaskDistributionChannelSelector::STRATEGY_RANDOM_BALANCED,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->distributionChannels()->sync($channels->values()->mapWithKeys(
            static fn (DistributionChannel $channel, int $index): array => [(int) $channel->id => ['sort_order' => $index]]
        )->all());

        foreach (range(1, 6) as $index) {
            $article = Article::query()->create([
                'title' => '均衡文章 '.$index,
                'slug' => 'balanced-article-'.$index,
                'excerpt' => '摘要',
                'content' => '正文',
                'category_id' => $fixtures['category']->id,
                'author_id' => $fixtures['author']->id,
                'task_id' => (int) $task->id,
                'status' => 'published',
                'review_status' => 'approved',
                'published_at' => now(),
            ]);

            app(DistributionOrchestrator::class)->enqueueForArticle($article);

            $this->assertSame(1, ArticleDistribution::query()->where('article_id', (int) $article->id)->count());
        }

        $counts = ArticleDistribution::query()
            ->whereIn('distribution_channel_id', $channels->pluck('id')->map(static fn ($id): int => (int) $id)->all())
            ->selectRaw('distribution_channel_id, COUNT(*) as aggregate_count')
            ->groupBy('distribution_channel_id')
            ->pluck('aggregate_count', 'distribution_channel_id')
            ->map(static fn ($count): int => (int) $count)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([2, 2, 2], $counts);
    }

    public function test_distribution_strategy_reuses_existing_article_channel_without_advancing_cursor(): void
    {
        Queue::fake();

        $fixtures = $this->taskFixtures();
        $channelOne = DistributionChannel::query()->create([
            'name' => '复用站点 1',
            'domain' => 'reuse-1.example.com',
            'endpoint_url' => 'https://reuse-1.example.com',
            'status' => 'active',
        ]);
        $channelTwo = DistributionChannel::query()->create([
            'name' => '复用站点 2',
            'domain' => 'reuse-2.example.com',
            'endpoint_url' => 'https://reuse-2.example.com',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '复用渠道分发任务',
            'title_library_id' => $fixtures['title_library']->id,
            'prompt_id' => $fixtures['prompt']->id,
            'ai_model_id' => $fixtures['ai_model']->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'distribution_only',
            'distribution_strategy' => TaskDistributionChannelSelector::STRATEGY_ROUND_ROBIN,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->distributionChannels()->sync([
            (int) $channelOne->id => ['sort_order' => 0],
            (int) $channelTwo->id => ['sort_order' => 1],
        ]);

        $firstArticle = Article::query()->create([
            'title' => '复用文章 1',
            'slug' => 'reuse-article-1',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        app(DistributionOrchestrator::class)->enqueueForArticle($firstArticle);
        app(DistributionOrchestrator::class)->enqueueForArticle($firstArticle);

        $this->assertSame(1, ArticleDistribution::query()->where('article_id', (int) $firstArticle->id)->count());
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $firstArticle->id,
            'distribution_channel_id' => (int) $channelOne->id,
        ]);
        $this->assertSame(1, (int) $task->fresh()->distribution_cursor);

        $secondArticle = Article::query()->create([
            'title' => '复用文章 2',
            'slug' => 'reuse-article-2',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $fixtures['category']->id,
            'author_id' => $fixtures['author']->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        app(DistributionOrchestrator::class)->enqueueForArticle($secondArticle);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $secondArticle->id,
            'distribution_channel_id' => (int) $channelTwo->id,
        ]);
        $this->assertSame(2, (int) $task->fresh()->distribution_cursor);
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
            'is_featured' => true,
            'is_hot' => true,
            'published_at' => now(),
        ]);
        $builtPayload = app(DistributionPayloadBuilder::class)->build($article->fresh());
        $this->assertTrue($builtPayload['article']['is_featured']);
        $this->assertTrue($builtPayload['article']['is_hot']);

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

    private function frontendCapabilityChannel(string $domain): DistributionChannel
    {
        $channel = DistributionChannel::query()->create([
            'name' => '远端能力 '.$domain,
            'domain' => $domain,
            'endpoint_url' => 'https://'.$domain,
            'channel_type' => 'geoflow_agent',
            'status' => 'active',
        ]);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_'.str_replace(['.', '-'], '_', $domain),
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_'.$domain),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        return $channel;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runtimeHomepageModules(): array
    {
        return [
            [
                'type' => 'hero',
                'title' => 'Runtime Hero',
                'body' => 'Runtime hero body',
                'image_url' => '/storage/runtime-hero.jpg',
                'link_text' => 'Open',
                'link_url' => '/',
                'enabled' => true,
                'sort_order' => 10,
            ],
            [
                'type' => 'rich_text',
                'title' => 'Runtime Rich Text',
                'body' => 'Runtime rich text body',
                'enabled' => true,
                'sort_order' => 20,
            ],
            [
                'type' => 'image_band',
                'title' => 'Runtime Image Band',
                'body' => 'Runtime image band body',
                'image_url' => '/storage/runtime-image.jpg',
                'enabled' => true,
                'sort_order' => 30,
            ],
            [
                'type' => 'metric_band',
                'title' => 'Runtime Metrics',
                'body' => "Metric A|42|pts\nMetric B|88|pts",
                'enabled' => true,
                'sort_order' => 40,
            ],
            [
                'type' => 'chart_band',
                'title' => 'Runtime Chart',
                'body' => "Chart A|64\nChart B|92",
                'enabled' => true,
                'sort_order' => 50,
            ],
            [
                'type' => 'feature_grid',
                'title' => 'Runtime Features',
                'body' => "Feature A|Feature body|/\nFeature B|Feature body|/",
                'enabled' => true,
                'sort_order' => 60,
            ],
            [
                'type' => 'article_collection',
                'title' => 'Runtime Articles',
                'data_source' => 'latest',
                'limit' => 3,
                'enabled' => true,
                'sort_order' => 70,
            ],
            [
                'type' => 'cta_band',
                'title' => 'Runtime CTA',
                'body' => 'Runtime CTA body',
                'link_text' => 'Continue',
                'link_url' => '/',
                'enabled' => true,
                'sort_order' => 80,
            ],
            [
                'type' => 'custom_html',
                'title' => 'Runtime Custom',
                'custom_html' => '<section><h3>Runtime Custom Heading</h3><p>Runtime custom body</p></section>',
                'enabled' => true,
                'sort_order' => 90,
            ],
        ];
    }

    private function freeTcpPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function waitForHttpServer(string $baseUrl): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $response = Http::timeout(1)->get($baseUrl.'/');
                if ($response->status() < 500) {
                    return;
                }
            } catch (\Throwable) {
                usleep(100000);
            }
        }

        $this->fail('PHP runtime target server did not start.');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
        }

        rmdir($path);
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
