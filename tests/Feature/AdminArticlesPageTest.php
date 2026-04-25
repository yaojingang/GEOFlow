<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台文章页（Blade）最小可用测试：鉴权、列表渲染、创建/编辑页路由。
 */
class AdminArticlesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_articles_page(): void
    {
        $this->get(route('admin.articles.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_articles_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_admin',
            'password' => 'secret-123',
            'email' => 'articles-admin@example.com',
            'display_name' => 'Articles Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee(__('admin.articles.page_title'))
            ->assertViewHas('articles')
            ->assertViewHas('filters');
    }

    public function test_authenticated_admin_can_open_article_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_create_admin',
            'password' => 'secret-123',
            'email' => 'articles-create-admin@example.com',
            'display_name' => 'Articles Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->assertSee(__('admin.article_create.page_heading'));
    }
}
