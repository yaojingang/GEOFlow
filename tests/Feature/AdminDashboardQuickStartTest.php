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
            ->assertSee(__('admin.dashboard.quick_start.title'))
            ->assertSee(__('admin.dashboard.quick_start.api_title'))
            ->assertSee(__('admin.dashboard.quick_start.material_title'))
            ->assertSee(__('admin.dashboard.quick_start.task_title'))
            ->assertSee(route('admin.ai-models.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.title-libraries.index'), false)
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee(route('admin.image-libraries.index'), false)
            ->assertSee(route('admin.authors.index'), false)
            ->assertSee(route('admin.tasks.create'), false);
    }
}
