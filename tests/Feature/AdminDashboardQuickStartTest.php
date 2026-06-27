<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardQuickStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_scenario_navigation_without_data_widgets(): void
    {
        $admin = Admin::query()->create([
            'username' => 'dashboard_quick_start_admin',
            'password' => 'secret-123',
            'email' => 'dashboard-quick-start@example.com',
            'display_name' => 'Dashboard Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $response
            ->assertOk()
            ->assertSee(__('admin.dashboard.navigation.single_site_title'))
            ->assertSee(__('admin.dashboard.navigation.multi_site_title'))
            ->assertSee(__('admin.dashboard.automation.title'))
            ->assertSee(__('admin.dashboard.automation.flow_title'))
            ->assertSee(__('admin.dashboard.automation.recommendations_title'))
            ->assertSee(__('admin.dashboard.automation.recommendations_empty'))
            ->assertDontSee('内容工程演示层')
            ->assertDontSee('工程')
            ->assertDontSee('�')
            ->assertSee(__('admin.dashboard.demo_journey.title'))
            ->assertSee(__('admin.dashboard.demo_journey.assets_title'))
            ->assertSee(__('admin.dashboard.demo_journey.quality_title'))
            ->assertSee(__('admin.dashboard.demo_journey.observation_title'))
            ->assertSee(__('admin.dashboard.automation.node_prompt_graph_title'))
            ->assertSee(__('admin.dashboard.automation.node_content_title'))
            ->assertSee(__('admin.dashboard.automation.node_authority_distribution_title'))
            ->assertSee(__('admin.dashboard.automation.step_label', ['step' => '01']))
            ->assertSee(__('admin.dashboard.automation.step_label', ['step' => '08']))
            ->assertSee('id="content-engineering-step-01"', false)
            ->assertSee('id="content-engineering-step-08"', false)
            ->assertSeeInOrder([
                __('admin.dashboard.automation.node_prompt_graph_title'),
                __('admin.dashboard.automation.node_knowledge_assets_title'),
                __('admin.dashboard.automation.node_evidence_structure_title'),
                __('admin.dashboard.automation.node_engineering_task_title'),
                __('admin.dashboard.automation.node_content_title'),
                __('admin.dashboard.automation.node_quality_gate_title'),
                __('admin.dashboard.automation.node_authority_distribution_title'),
                __('admin.dashboard.automation.node_measurement_title'),
            ])
            ->assertSee(__('admin.dashboard.automation.lane_single_title'))
            ->assertSee(__('admin.dashboard.automation.lane_multi_title'))
            ->assertSee(__('admin.dashboard.automation.lane_feedback_title'))
            ->assertSee(__('admin.dashboard.navigation.ai_config_title'))
            ->assertSee(__('admin.dashboard.navigation.materials_title'))
            ->assertSee('知识资产与证据库')
            ->assertDontSee('建设素材库')
            ->assertSee(__('admin.dashboard.navigation.create_task_title'))
            ->assertSee(__('admin.dashboard.navigation.articles_title'))
            ->assertSee(__('admin.dashboard.navigation.prompt_config_title'))
            ->assertSee(__('admin.dashboard.navigation.body_prompt_label'))
            ->assertSee(__('admin.dashboard.navigation.special_prompt_label'))
            ->assertSee(__('admin.dashboard.navigation.admin_users_title'))
            ->assertSee(__('admin.dashboard.navigation.distribution_channels_title'))
            ->assertSee(__('admin.dashboard.navigation.distribution_jobs_title'))
            ->assertSee(__('admin.dashboard.skill_resources.title'))
            ->assertSee(__('admin.dashboard.skill_resources.template_title'))
            ->assertSee(__('admin.dashboard.skill_resources.design_title'))
            ->assertSee(__('admin.dashboard.skill_resources.cli_title'))
            ->assertSee(__('admin.dashboard.quick_start.title'))
            ->assertSee(__('admin.dashboard.quick_start.api_title'))
            ->assertSee(__('admin.dashboard.quick_start.material_title'))
            ->assertSee(__('admin.dashboard.quick_start.task_title'))
            ->assertDontSee(__('admin.dashboard.analytics_card_title'))
            ->assertDontSee(__('admin.dashboard.analytics_card_button'))
            ->assertDontSee(__('admin.dashboard.category_distribution'))
            ->assertDontSee(__('admin.dashboard.system_performance'))
            ->assertDontSee(__('admin.dashboard.latest_articles'))
            ->assertDontSee(__('admin.dashboard.task_health'))
            ->assertDontSee(__('admin.dashboard.material_health'))
            ->assertDontSee(__('admin.dashboard.ai_health'))
            ->assertDontSee(__('admin.dashboard.popular_articles'))
            ->assertDontSee(__('admin.dashboard.content_funnel'))
            ->assertSee(route('admin.ai-models.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.title-libraries.index'), false)
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee(route('admin.image-libraries.index'), false)
            ->assertSee(route('admin.authors.index'), false)
            ->assertSee(route('admin.materials.index'), false)
            ->assertSee(route('admin.tasks.create'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.site-settings.index'), false)
            ->assertSee(route('admin.dashboard'), false)
            ->assertSee(route('admin.analytics'), false)
            ->assertSee(route('admin.ai-prompts'), false)
            ->assertSee(route('admin.ai-special-prompts'), false)
            ->assertSee(route('admin.admin-users.index'), false)
            ->assertSee(route('admin.distribution.index'), false)
            ->assertSee(route('admin.distribution.create'), false)
            ->assertSee(route('admin.distribution.jobs'), false)
            ->assertSee('https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-template', false)
            ->assertSee('https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-design', false)
            ->assertSee('https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-cli', false);

        $html = $response->getContent();
        $this->assertGreaterThanOrEqual(1, substr_count($html, route('admin.knowledge-bases.index')));
        $this->assertGreaterThanOrEqual(1, substr_count($html, route('admin.title-libraries.index')));
        $this->assertGreaterThanOrEqual(1, substr_count($html, route('admin.keyword-libraries.index')));
        $this->assertGreaterThanOrEqual(1, substr_count($html, route('admin.image-libraries.index')));
        $this->assertGreaterThanOrEqual(1, substr_count($html, route('admin.authors.index')));
        $this->assertStringContainsString(__('admin.dashboard.automation.metric_materials', ['count' => 0]), $html);
        $this->assertStringContainsString(__('admin.dashboard.automation.metric_vectorized', ['done' => 0, 'total' => 0]), $html);
        $this->assertStringContainsString(__('admin.dashboard.automation.metric_ai_today', ['count' => 0]), $html);
        $this->assertStringContainsString(__('admin.dashboard.automation.running_badge', ['count' => 0]), $html);
        $this->assertStringContainsString(__('admin.dashboard.automation.attention_badge', ['count' => 0]), $html);
        $this->assertStringContainsString(__('admin.dashboard.automation.health_task_meta', ['running' => 0, 'queued' => 0, 'failed' => 0]), $html);
        $this->assertStringContainsString(__('admin.dashboard.automation.health_content_meta', ['published' => 0, 'drafts' => 0, 'pending' => 0]), $html);
        $this->assertStringNotContainsString(__('admin.dashboard.automation.metric_materials', ['count' => 449]), $html);
        $this->assertStringNotContainsString(__('admin.dashboard.automation.metric_vectorized', ['done' => 584, 'total' => 612]), $html);
        $this->assertStringNotContainsString(__('admin.dashboard.automation.metric_ai_today', ['count' => 74]), $html);
    }

    public function test_dashboard_description_copy_does_not_end_with_sentence_periods(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            app()->setLocale($locale);

            $this->assertDashboardDescriptionsDoNotEndWithSentencePeriods(
                trans('admin.dashboard'),
                'admin.dashboard'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $copy
     */
    private function assertDashboardDescriptionsDoNotEndWithSentencePeriods(array $copy, string $path): void
    {
        foreach ($copy as $key => $value) {
            $nextPath = $path.'.'.$key;

            if (is_array($value)) {
                $this->assertDashboardDescriptionsDoNotEndWithSentencePeriods($value, $nextPath);

                continue;
            }

            if (! is_string($value) || ! $this->isDashboardDescriptionKey((string) $key)) {
                continue;
            }

            $this->assertFalse(
                str_ends_with($value, '。') || str_ends_with($value, '.'),
                "{$nextPath} should not end with sentence punctuation."
            );
        }
    }

    private function isDashboardDescriptionKey(string $key): bool
    {
        return $key === 'desc'
            || $key === 'subtitle'
            || $key === 'recommendations_empty'
            || str_ends_with($key, '_desc');
    }

    public function test_welcome_modal_dismiss_url_is_relative_when_app_url_differs_from_origin(): void
    {
        config(['app.url' => 'https://configured.example']);

        $admin = Admin::query()->create([
            'username' => 'dashboard_origin_admin',
            'password' => 'secret-123',
            'email' => 'dashboard-origin@example.com',
            'display_name' => 'Dashboard Origin Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $dismissPath = AdminWeb::routePath('admin.welcome.dismiss');
        $escapedDismissPath = str_replace('/', '\\/', $dismissPath);
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString($escapedDismissPath, $html);
        $this->assertStringNotContainsString('https:\/\/configured.example'.$escapedDismissPath, $html);
        $this->assertStringNotContainsString('https://configured.example'.$dismissPath, $html);
    }

    public function test_welcome_modal_dismiss_url_keeps_configured_subdirectory_without_absolute_host(): void
    {
        config(['app.url' => 'https://configured.example/geoflow']);

        $admin = Admin::query()->create([
            'username' => 'dashboard_subdirectory_admin',
            'password' => 'secret-123',
            'email' => 'dashboard-subdirectory@example.com',
            'display_name' => 'Dashboard Subdirectory Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $dismissPath = AdminWeb::routePath('admin.welcome.dismiss');
        $escapedDismissPath = str_replace('/', '\\/', $dismissPath);
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringStartsWith('/geoflow/', $dismissPath);
        $this->assertStringContainsString($escapedDismissPath, $html);
        $this->assertStringNotContainsString('https:\/\/configured.example'.$escapedDismissPath, $html);
        $this->assertStringNotContainsString('https://configured.example'.$dismissPath, $html);
    }

    public function test_project_intro_auto_opens_once_and_footer_link_remains_available(): void
    {
        $admin = Admin::query()->create([
            'username' => 'dashboard_project_intro_admin',
            'password' => 'secret-123',
            'email' => 'dashboard-project-intro@example.com',
            'display_name' => 'Project Intro Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $firstResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk();

        $firstHtml = $firstResponse->getContent();
        $this->assertStringContainsString('data-open-admin-welcome', $firstHtml);
        $this->assertStringContainsString('"shouldAutoOpen":true', $firstHtml);
        $this->assertSame(
            'intro:'.config('geoflow.welcome_intro_version'),
            (string) $admin->fresh()?->welcome_seen_version
        );

        $secondHtml = $this->actingAs($admin->fresh(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-open-admin-welcome', $secondHtml);
        $this->assertStringContainsString('"shouldAutoOpen":false', $secondHtml);
        $this->assertStringContainsString(__('admin.footer.project_intro_link'), $secondHtml);
    }

    public function test_admin_footer_links_to_locale_specific_help_docs(): void
    {
        $admin = Admin::query()->create([
            'username' => 'dashboard_help_docs_admin',
            'password' => 'secret-123',
            'email' => 'dashboard-help-docs@example.com',
            'display_name' => 'Help Docs Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $zhHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('admin.footer.help_docs_link'), $zhHtml);
        $this->assertStringContainsString('https://github.com/yaojingang/GEOFlow/wiki', $zhHtml);
        $this->assertStringNotContainsString('https://github.com/yaojingang/GEOFlow/wiki/Home-English', $zhHtml);

        session(['locale' => 'en']);

        $enHtml = $this->actingAs($admin->fresh(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Help docs', $enHtml);
        $this->assertStringContainsString('https://github.com/yaojingang/GEOFlow/wiki/Home-English', $enHtml);
    }
}
