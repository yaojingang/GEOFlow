<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\GeoArticleDraft;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGeoOperationsModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_task_article_material_ai_and_site_pages_show_geo_operations_panel(): void
    {
        $admin = $this->createAdmin();

        $routes = [
            route('admin.tasks.index') => '内容生产任务',
            route('admin.articles.index') => 'GEO 内容链路',
            route('admin.materials.index') => 'GEO 素材就绪度',
            route('admin.ai.configurator') => 'GEO AI 配置就绪',
            route('admin.site-settings.index') => 'GEO 站点发布设置',
        ];

        foreach ($routes as $url => $moduleLabel) {
            $this->actingAs($admin, 'admin')
                ->get($url)
                ->assertOk()
                ->assertSee('data-admin-gui-shell', false)
                ->assertSee('data-admin-primary-nav', false)
                ->assertSee('data-geo-primary-entry', false)
                ->assertSee('GEO 获客主线')
                ->assertSee('企业资料')
                ->assertSee('检视任务')
                ->assertSee('引用来源')
                ->assertSee('内容资产')
                ->assertSee('发布复测')
                ->assertSee('GEO 运营就绪')
                ->assertSee($moduleLabel)
                ->assertSee('进入 GEO 工作台')
                ->assertSee(route('admin.geo.workspace'), false)
                ->assertSee(route('admin.web-workbench.index'), false)
                ->assertSee(route('admin.geo.citation-sources.index'), false)
                ->assertSee(route('admin.articles.index'), false)
                ->assertSee(route('admin.tasks.index'), false);
        }
    }

    public function test_article_list_marks_geo_source_and_links_back_to_draft(): void
    {
        $admin = $this->createAdmin('article_geo_ops_admin');
        $category = Category::query()->create([
            'name' => 'GEO内容',
            'slug' => 'geo-content',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOAmplify',
        ]);
        $article = Article::query()->create([
            'title' => 'GEO 来源识别文章',
            'slug' => 'geo-source-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'metadata' => [
                'source' => 'geo_reference_imitation',
                'target_question' => '重庆全屋定制怎么选',
            ],
        ]);
        $organization = Organization::query()->create([
            'name' => '默认组织',
            'owner_admin_id' => $admin->id,
            'status' => 'active',
        ]);
        $draft = GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'article_id' => $article->id,
            'title' => 'GEO 来源识别文章',
            'content_markdown' => '正文',
            'status' => 'converted',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('GEO 引用仿写')
            ->assertSee('重庆全屋定制怎么选')
            ->assertSee(route('admin.geo.article-drafts.edit', ['draftId' => (int) $draft->id]), false);
    }

    private function createAdmin(string $username = 'geo_ops_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'GEO Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
