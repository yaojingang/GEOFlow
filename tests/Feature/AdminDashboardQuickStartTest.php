<?php

namespace Tests\Feature;

use App\Models\Admin;
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
            ->assertSee(__('admin.dashboard.navigation.ai_config_title'))
            ->assertSee(__('admin.dashboard.navigation.materials_title'))
            ->assertSee('配置素材库')
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
            ->assertDontSee(__('admin.dashboard.total_articles'))
            ->assertDontSee(__('admin.dashboard.published'))
            ->assertDontSee(__('admin.dashboard.ai_generated'))
            ->assertDontSee(__('admin.dashboard.total_views'))
            ->assertDontSee(__('admin.dashboard.active_tasks'))
            ->assertDontSee(__('admin.dashboard.ai_models'))
            ->assertDontSee(__('admin.dashboard.material_total'))
            ->assertDontSee(__('admin.dashboard.pending_review'))
            ->assertDontSee(__('admin.dashboard.todo_title'))
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
            ->assertSee(route('admin.tasks.create'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.site-settings.index'), false)
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
        $this->assertSame(1, substr_count($html, route('admin.knowledge-bases.index')));
        $this->assertSame(1, substr_count($html, route('admin.title-libraries.index')));
        $this->assertSame(1, substr_count($html, route('admin.keyword-libraries.index')));
        $this->assertSame(1, substr_count($html, route('admin.image-libraries.index')));
        $this->assertSame(1, substr_count($html, route('admin.authors.index')));
    }
}
