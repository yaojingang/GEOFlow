<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台栏目管理页最小可用测试：鉴权、页面可达、入口链接正确。
 */
class AdminCategoriesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_categories_page(): void
    {
        $this->get(route('admin.categories.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_categories_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_admin',
            'password' => 'secret-123',
            'email' => 'categories-admin@example.com',
            'display_name' => 'Categories Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(__('admin.categories.page_title'));
    }

    public function test_articles_page_category_manage_button_points_to_categories_route(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_link_admin',
            'password' => 'secret-123',
            'email' => 'categories-link-admin@example.com',
            'display_name' => 'Categories Link Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(route('admin.categories.index'));
    }
}
