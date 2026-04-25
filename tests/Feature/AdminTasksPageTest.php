<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台任务页（Blade）最小可用测试：鉴权与页面渲染。
 */
class AdminTasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_tasks_page(): void
    {
        $this->get(route('admin.tasks.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_tasks_page_with_filters(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin',
            'password' => 'secret-123',
            'email' => 'tasks-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index', ['keyword' => 'demo', 'status' => 'active']))
            ->assertOk()
            ->assertSee(__('admin.tasks.page_title'))
            ->assertSee(__('admin.tasks.empty_title'))
            ->assertViewHas('tasks')
            ->assertViewHas('taskI18n');
    }

    public function test_authenticated_admin_can_open_task_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_create',
            'password' => 'secret-123',
            'email' => 'tasks-admin-create@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.page_heading'));
    }
}
