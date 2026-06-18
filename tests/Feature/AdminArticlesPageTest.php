<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_article_edit_page_renders_markdown_editor_assets_and_upload_route(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_editor_admin',
            'password' => 'secret-123',
            'email' => 'articles-editor-admin@example.com',
            'display_name' => 'Articles Editor Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '编辑器分类',
            'slug' => 'editor-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => 'Markdown 编辑器测试文章',
            'slug' => 'markdown-editor-article',
            'excerpt' => '摘要',
            'content' => "## 小节\n\n正文",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertSee('vendor/vditor/dist/index.min.js', false)
            ->assertSee('vendor/cropperjs/cropper.min.js', false)
            ->assertSee(route('admin.articles.editor.images.upload', ['articleId' => (int) $article->id], false), false)
            ->assertSee(route('admin.articles.editor.wechat-html', []), false)
            ->assertSee('id="content-editor"', false)
            ->assertSee('id="article-editor-copy-markdown"', false)
            ->assertSee('id="article-editor-copy-wechat-html"', false)
            ->assertSee('id="article-editor-quick-image-input"', false)
            ->assertSee('id="article-editor-context-menu"', false)
            ->assertSee(__('admin.article_editor.copy.button'), false)
            ->assertSee(__('admin.article_editor.wechat.button'), false)
            ->assertSee('navigator.clipboard.writeText', false)
            ->assertSee('ClipboardItem', false)
            ->assertSee(__('admin.article_editor.quick_actions.image'), false)
            ->assertSee(__('admin.article_editor.quick_actions.heading'), false);
    }

    public function test_admin_can_export_article_editor_wechat_html(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_wechat_export_admin',
            'password' => 'secret-123',
            'email' => 'articles-wechat-export@example.com',
            'display_name' => 'Articles WeChat Export Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.wechat-html'), [
                'content' => "## 小节\n\n正文 **重点**\n\n<script>alert(1)</script>\n\n| A | B |\n| --- | --- |\n| 1 | 2 |",
            ]);

        $response->assertOk()
            ->assertJsonPath('message', __('admin.article_editor.wechat.success'));

        $html = (string) $response->json('html');
        $this->assertStringContainsString('data-geoflow-export="wechat-article"', $html);
        $this->assertStringContainsString('style="', $html);
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_admin_can_upload_article_editor_image_and_receive_markdown(): void
    {
        Storage::fake('public');

        $admin = Admin::query()->create([
            'username' => 'articles_image_upload_admin',
            'password' => 'secret-123',
            'email' => 'articles-image-upload@example.com',
            'display_name' => 'Articles Image Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '图片分类',
            'slug' => 'image-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => '文章图片上传测试',
            'slug' => 'article-editor-image-upload',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.images.upload', ['articleId' => (int) $article->id]), [
                'image' => UploadedFile::fake()->image('geo-flow-editor.png', 640, 360),
                'alt' => 'GEOFlow 编辑器截图',
                'position' => 12,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', __('admin.article_editor.message.upload_success'))
            ->assertJsonPath('image.alt', 'GEOFlow 编辑器截图');

        $markdown = (string) $response->json('image.markdown');
        $url = (string) $response->json('image.url');

        $this->assertStringStartsWith('![GEOFlow 编辑器截图](/storage/uploads/images/', $markdown);
        $this->assertStringStartsWith('/storage/uploads/images/', $url);
        $this->assertSame(1, ImageLibrary::query()->where('name', '文章编辑器图片')->count());
        $this->assertSame(1, Image::query()->where('original_name', 'GEOFlow 编辑器截图')->count());
        $this->assertSame(1, ArticleImage::query()->where('article_id', (int) $article->id)->count());

        Storage::disk('public')->assertExists(ltrim(substr($url, strlen('/storage/')), '/'));
    }

    public function test_article_editor_image_upload_rejects_non_images(): void
    {
        Storage::fake('public');

        $admin = Admin::query()->create([
            'username' => 'articles_image_reject_admin',
            'password' => 'secret-123',
            'email' => 'articles-image-reject@example.com',
            'display_name' => 'Articles Image Reject Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '图片校验分类',
            'slug' => 'image-validation-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => '文章图片校验测试',
            'slug' => 'article-editor-image-validation',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.images.upload', ['articleId' => (int) $article->id]), [
                'image' => UploadedFile::fake()->create('not-image.txt', 4, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_admin_can_save_article_hot_and_featured_flags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_flags_admin',
            'password' => 'secret-123',
            'email' => 'articles-flags@example.com',
            'display_name' => 'Articles Flags Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => '推荐标记测试文章',
                'excerpt' => '摘要',
                'content' => '正文',
                'keywords' => 'GEO',
                'meta_description' => 'Meta',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'published',
                'review_status' => 'approved',
                'is_hot' => '1',
                'is_featured' => '1',
            ])
            ->assertRedirect();

        $article = Article::query()->where('title', '推荐标记测试文章')->firstOrFail();

        $this->assertTrue((bool) $article->is_hot);
        $this->assertTrue((bool) $article->is_featured);
    }

    public function test_article_list_shows_hot_and_featured_badges(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_badges_admin',
            'password' => 'secret-123',
            'email' => 'articles-badges@example.com',
            'display_name' => 'Articles Badges Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        Article::query()->create([
            'title' => '后台标签展示文章',
            'slug' => 'admin-badges-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_hot' => true,
            'is_featured' => true,
            'published_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(__('admin.articles.badge.hot'))
            ->assertSee(__('admin.articles.badge.featured'));
    }

    public function test_article_list_shows_distribution_status_badge(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_distribution_status_admin',
            'password' => 'secret-123',
            'email' => 'articles-distribution-status@example.com',
            'display_name' => 'Articles Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '分发分类',
            'slug' => 'distribution-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '目标站点',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com/geoflow/agent',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '分发状态展示文章',
            'slug' => 'distribution-status-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => 'article-list-synced',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.article_status.synced'));
    }

    public function test_article_batch_urls_are_relative_when_app_url_differs_from_origin(): void
    {
        config(['app.url' => 'https://configured.example']);

        $admin = Admin::query()->create([
            'username' => 'articles_relative_batch_admin',
            'password' => 'secret-123',
            'email' => 'articles-relative-batch@example.com',
            'display_name' => 'Articles Relative Batch Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '批量操作分类',
            'slug' => 'batch-actions-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => '批量操作相对路径文章',
            'slug' => 'relative-batch-actions-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $listHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            route('admin.articles.batch.update-status', []),
            route('admin.articles.batch.update-review', []),
            route('admin.articles.batch.delete', []),
        ] as $path) {
            $escapedPath = str_replace('/', '\\/', $path);

            $this->assertStringContainsString($escapedPath, $listHtml);
            $this->assertStringNotContainsString('https://configured.example'.$path, $listHtml);
            $this->assertStringNotContainsString('https:\/\/configured.example'.$escapedPath, $listHtml);
        }
        $this->assertStringContainsString(
            'action="'.route('admin.articles.batch.update-status', []).'"',
            $listHtml
        );

        $article->delete();

        $trashHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['trashed' => 1]))
            ->assertOk()
            ->getContent();

        foreach ([
            route('admin.articles.batch.restore', []),
            route('admin.articles.batch.force-delete', []),
            route('admin.articles.trash.empty', []),
        ] as $path) {
            $escapedPath = str_replace('/', '\\/', $path);

            $this->assertStringContainsString($escapedPath, $trashHtml);
            $this->assertStringNotContainsString('https://configured.example'.$path, $trashHtml);
            $this->assertStringNotContainsString('https:\/\/configured.example'.$escapedPath, $trashHtml);
        }
    }

    public function test_admin_brand_stays_geoflow_when_public_site_name_changes(): void
    {
        $admin = Admin::query()->create([
            'username' => 'admin_brand_admin',
            'password' => 'secret-123',
            'email' => 'admin-brand@example.com',
            'display_name' => 'Brand Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => 'Public Frontend Name',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('GEOFlow')
            ->assertDontSee('Public Frontend Name');
    }
}
