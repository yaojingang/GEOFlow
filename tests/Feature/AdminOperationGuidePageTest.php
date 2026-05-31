<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperationGuidePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_guide_is_rightmost_primary_navigation_item(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->get('/geo_admin/dashboard')
            ->assertOk()
            ->assertSee('操作说明')
            ->assertSee('data-admin-operation-guide-nav', false)
            ->assertSee('/geo_admin/operation-guide', false);

        $html = $response->getContent();

        $this->assertGreaterThan(
            strrpos($html, '用户'),
            strrpos($html, '操作说明'),
            '操作说明应该出现在一级导航最右边。'
        );
    }

    public function test_authenticated_admin_can_open_operation_guide_page(): void
    {
        $admin = $this->createAdmin('operation_guide_reader');

        $this->actingAs($admin, 'admin')
            ->get('/geo_admin/operation-guide')
            ->assertOk()
            ->assertSee('data-admin-operation-guide', false)
            ->assertSee('系统操作说明')
            ->assertSee('先完成企业资料')
            ->assertSee('再做 AI 检视')
            ->assertSee('沉淀引用来源')
            ->assertSee('生成内容资产')
            ->assertSee('发布后复测')
            ->assertSee('怎么实际操作')
            ->assertSee('点击哪里')
            ->assertSee('填写什么')
            ->assertSee('完成标准')
            ->assertSee('常见卡点')
            ->assertSee('不要只发文章')
            ->assertSee('日常最短路径')
            ->assertSee(route('admin.geo.workspace'), false)
            ->assertSee(route('admin.web-workbench.index'), false)
            ->assertSee(route('admin.geo.citation-sources.index'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.tasks.index'), false);
    }

    private function createAdmin(string $username = 'operation_guide_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Operation Guide Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
