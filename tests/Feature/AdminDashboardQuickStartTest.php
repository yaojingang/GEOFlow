<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardQuickStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_quick_start_steps_and_links(): void
    {
        $admin = Admin::query()->create([
            'username' => 'dashboard_quick_start_admin',
            'password' => 'secret-123',
            'email' => 'dashboard-quick-start@example.com',
            'display_name' => 'Dashboard Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-admin-gui-shell', false)
            ->assertSee('data-admin-primary-nav', false)
            ->assertSee('data-admin-home-command-center', false)
            ->assertSee('data-admin-dashboard-detail-stack', false)
            ->assertSee('展开查看详细数据和历史面板')
            ->assertSee('GEO 获客中控台')
            ->assertSee('GEO获客')
            ->assertSee('AI问答')
            ->assertSee('内容资产')
            ->assertSee('发布复测')
            ->assertSee('操作说明')
            ->assertSee('/geo_admin/operation-guide', false)
            ->assertSee(route('admin.geo.workspace'), false)
            ->assertSee(__('admin.dashboard.quick_start.title'))
            ->assertSee(__('admin.dashboard.quick_start.api_title'))
            ->assertSee(__('admin.dashboard.quick_start.material_title'))
            ->assertSee(__('admin.dashboard.quick_start.task_title'))
            ->assertSee(__('admin.dashboard.content_funnel'))
            ->assertSee(__('admin.dashboard.todo_title'))
            ->assertSee(__('admin.dashboard.task_health'))
            ->assertSee(__('admin.dashboard.material_health'))
            ->assertSee(__('admin.dashboard.ai_health'))
            ->assertSee(__('admin.dashboard.url_import_health'))
            ->assertSee(__('admin.dashboard.popular_articles'))
            ->assertSee(route('admin.ai-models.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.title-libraries.index'), false)
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee(route('admin.image-libraries.index'), false)
            ->assertSee(route('admin.authors.index'), false)
            ->assertSee(route('admin.tasks.create'), false);
    }
}
