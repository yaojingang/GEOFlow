<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Support\AdminWeb;
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
            ->assertSee(AdminWeb::routePath('admin.categories.index'), false);
    }

    public function test_reserved_permalink_namespace_is_skipped_when_generating_a_category_slug(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_reserved_slug_admin',
            'password' => 'secret-123',
            'email' => 'categories-reserved-slug@example.com',
            'display_name' => 'Categories Reserved Slug Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.categories.store'), [
                'name' => 'API',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'name' => 'API',
            'slug' => 'api-2',
        ]);
    }

    public function test_category_with_only_trashed_articles_is_still_not_deletable(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_delete_admin',
            'password' => 'secret-123',
            'email' => 'categories-delete-admin@example.com',
            'display_name' => 'Categories Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $category = Category::query()->create([
            'name' => 'Soft Deleted Category',
            'slug' => 'soft-deleted-category',
            'description' => '',
            'sort_order' => 0,
        ]);

        $author = Author::query()->create([
            'name' => 'Category Author',
            'email' => 'category-author@example.com',
        ]);

        $article = Article::query()->create([
            'title' => 'Soft deleted article',
            'slug' => 'soft-deleted-article',
            'excerpt' => '',
            'content' => 'Body',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $article->delete();

        $deleteUrl = route('admin.categories.delete', ['categoryId' => (int) $category->id]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(__('admin.categories.article_count_badge', ['count' => 1]))
            ->assertSee(__('admin.categories.article_count_trashed_hint', ['count' => 1]))
            ->assertDontSee($deleteUrl, false);

        $this->actingAs($admin, 'admin')
            ->post($deleteUrl)
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('categories', [
            'id' => (int) $category->id,
        ]);
    }
}
