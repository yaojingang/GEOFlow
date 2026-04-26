<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 素材管理模块最小可用测试：
 * - 路由鉴权
 * - 主要列表/创建页可访问
 * - 知识库创建链路可用
 */
class AdminMaterialsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guest_is_redirected_from_material_pages(): void
    {
        $routes = [
            'admin.materials.index',
            'admin.authors.index',
            'admin.keyword-libraries.index',
            'admin.title-libraries.index',
            'admin.image-libraries.index',
            'admin.knowledge-bases.index',
            'admin.url-import',
            'admin.url-import.history',
        ];

        foreach ($routes as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('admin.login'));
        }

        $this->get(route('admin.keyword-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.title-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.image-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => 1]))->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_open_material_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_admin',
            'password' => 'secret-123',
            'email' => 'materials-admin@example.com',
            'display_name' => 'Materials Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.page_title'))
            ->assertSee(__('admin.materials.url_import'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.index'))
            ->assertOk()
            ->assertSee(__('admin.authors.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.keyword_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.title_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.image_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.create'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee(__('admin.url_import.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.history'))
            ->assertOk()
            ->assertSee(__('admin.url_import_history.page_title'));
    }

    public function test_admin_can_create_knowledge_base_from_form(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_create_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-create-admin@example.com',
            'display_name' => 'Knowledge Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '测试知识库',
                'description' => '测试描述',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
            ]);

        $response->assertRedirect(route('admin.knowledge-bases.index'));
        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '测试知识库',
            'file_type' => 'markdown',
        ]);
        $this->assertGreaterThan(0, KnowledgeBase::query()->count());
    }

    public function test_admin_can_create_url_import_placeholder_job(): void
    {
        $admin = Admin::query()->create([
            'username' => 'url_import_admin',
            'password' => 'secret-123',
            'email' => 'url-import-admin@example.com',
            'display_name' => 'Url Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'https://example.com/report',
                'project_name' => '示例项目',
                'outputs' => ['knowledge', 'keywords', 'titles', 'images'],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('url_import_jobs', [
            'url' => 'https://example.com/report',
            'source_domain' => 'example.com',
            'status' => 'queued',
            'created_by' => 'url_import_admin',
        ]);
    }

    public function test_admin_can_open_all_material_detail_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_detail_admin',
            'password' => 'secret-123',
            'email' => 'materials-detail-admin@example.com',
            'display_name' => 'Materials Detail Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库A',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库A',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库A',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'demo.png',
            'original_name' => 'demo.png',
            'file_name' => 'demo.png',
            'file_path' => 'storage/uploads/images/demo.png',
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 100,
            'height' => 100,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '知识库A',
            'description' => 'desc',
            'content' => '知识内容',
            'character_count' => 4,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 4,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]))
            ->assertOk()
            ->assertSee($keywordLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee($titleLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertSee($imageLibrary->name)
            ->assertSee('storage/uploads/images/demo.png');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_detail.heading'));
    }

    public function test_admin_can_manage_keyword_and_title_details(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_ops_admin',
            'password' => 'secret-123',
            'email' => 'materials-ops-admin@example.com',
            'display_name' => 'Materials Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库B',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库B',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '增长策略',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.titles.store', ['libraryId' => (int) $titleLibrary->id]), [
            'title' => '增长策略完整指南',
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '增长策略完整指南',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.import', ['libraryId' => (int) $titleLibrary->id]), [
            'titles_text' => "标题A|关键词A\n标题B",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '标题A',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $titleLibrary->id]), [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => 1,
            'title_count' => 3,
            'title_style' => 'professional',
            'custom_prompt' => '',
        ])->assertSessionHasErrors();
    }

    public function test_admin_can_upload_image_and_knowledge_file_from_detail_flow(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_upload_admin',
            'password' => 'secret-123',
            'email' => 'materials-upload-admin@example.com',
            'display_name' => 'Materials Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库C',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        $image = UploadedFile::fake()->image('banner.png', 100, 100);
        $this->actingAs($admin, 'admin')->post(route('admin.image-libraries.images.upload', ['libraryId' => (int) $imageLibrary->id]), [
            'images' => [$image],
        ])->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]));

        $this->assertDatabaseHas('images', [
            'library_id' => (int) $imageLibrary->id,
            'original_name' => 'banner.png',
        ]);

        $knowledgeFile = UploadedFile::fake()->createWithContent('manual.md', "# 标题\n内容段落");
        $this->actingAs($admin, 'admin')->post(route('admin.knowledge-bases.upload'), [
            'name' => '上传知识库',
            'description' => '测试上传',
            'knowledge_file' => $knowledgeFile,
        ])->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '上传知识库',
        ]);
    }
}
