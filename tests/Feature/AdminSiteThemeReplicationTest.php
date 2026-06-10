<?php

namespace Tests\Feature;

use App\Jobs\IterateSiteThemeReplicationJob;
use App\Jobs\RunSiteThemeReplicationJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationLog;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplication\ThemeComplianceGuard;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackageService;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AdminSiteThemeReplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_settings_page_shows_theme_replication_entry(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.entry_title'))
            ->assertSee(route('admin.site-settings.theme-replications.create'), false);
    }

    public function test_admin_can_open_theme_replication_create_page(): void
    {
        $this->activeChatModel();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.create'))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.create_heading'))
            ->assertSee(__('admin.theme_replication.field.home_url'));
    }

    public function test_admin_can_create_theme_replication_task(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();

        $model = $this->activeChatModel();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.store'), [
                'name' => 'Brand Clone',
                'theme_id' => 'brand-clone',
                'base_theme_id' => '',
                'ai_model_id' => $model->id,
                'style_preference' => 'brand_site',
                'home_url' => 'https://example.com/',
                'category_url' => 'https://example.com/blog',
                'article_url' => 'https://example.com/blog/demo',
                'compliance_ack' => '1',
            ]);

        $replication = SiteThemeReplication::query()->where('theme_id', 'brand-clone')->first();

        $this->assertNotNull($replication);
        $response->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => $replication->id]));
        $this->assertSame(SiteThemeReplication::STATUS_QUEUED, $replication->status);
        $this->assertSame('brand_site', $replication->style_preference);
        $this->assertSame(2, SiteThemeReplicationLog::query()->where('replication_id', $replication->id)->count());
        Queue::assertPushed(
            RunSiteThemeReplicationJob::class,
            fn (RunSiteThemeReplicationJob $job): bool => $job->replicationId === (int) $replication->id
                && $job->queue === 'theme-replication'
        );
    }

    public function test_theme_replication_rejects_private_reference_urls(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.store'), [
                'name' => 'Private Clone',
                'theme_id' => 'private-clone',
                'ai_model_id' => $this->activeChatModel()->id,
                'style_preference' => 'content_site',
                'home_url' => 'http://127.0.0.1:8080',
                'category_url' => 'https://example.com/list',
                'article_url' => 'https://example.com/article',
                'compliance_ack' => '1',
            ])
            ->assertSessionHasErrors('home_url');

        $this->assertDatabaseMissing('site_theme_replications', ['theme_id' => 'private-clone']);
    }

    public function test_theme_replication_reports_invalid_reference_url_on_matching_field(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.store'), [
                'name' => 'Private Category Clone',
                'theme_id' => 'private-category-clone',
                'ai_model_id' => $this->activeChatModel()->id,
                'style_preference' => 'content_site',
                'home_url' => 'https://example.com',
                'category_url' => 'http://127.0.0.1:8080/list',
                'article_url' => 'https://example.com/article',
                'compliance_ack' => '1',
            ])
            ->assertSessionHasErrors('category_url');

        $this->assertDatabaseMissing('site_theme_replications', ['theme_id' => 'private-category-clone']);
    }

    public function test_theme_replication_rejects_unknown_base_theme(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.store'), [
                'name' => 'Unknown Base Clone',
                'theme_id' => 'unknown-base-clone',
                'base_theme_id' => 'missing-base-theme',
                'ai_model_id' => $this->activeChatModel()->id,
                'style_preference' => 'content_site',
                'home_url' => 'https://example.com',
                'category_url' => 'https://example.com/category',
                'article_url' => 'https://example.com/article',
                'compliance_ack' => '1',
            ])
            ->assertSessionHasErrors('base_theme_id');

        $this->assertDatabaseMissing('site_theme_replications', ['theme_id' => 'unknown-base-clone']);
    }

    public function test_theme_replication_requires_active_chat_model(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $embeddingModel = AiModel::query()->create([
            'name' => 'Embedding Only',
            'model_id' => 'embedding-model',
            'model_type' => 'embedding',
            'api_key' => 'secret',
            'api_url' => 'https://api.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.store'), [
                'name' => 'Invalid Model Clone',
                'theme_id' => 'invalid-model-clone',
                'ai_model_id' => $embeddingModel->id,
                'style_preference' => 'content_site',
                'home_url' => 'https://example.com',
                'category_url' => 'https://example.com/category',
                'article_url' => 'https://example.com/article',
                'compliance_ack' => '1',
            ])
            ->assertSessionHasErrors('ai_model_id');
    }

    public function test_theme_replication_show_displays_logs(): void
    {
        $replication = SiteThemeReplication::query()->create([
            'name' => 'Show Clone',
            'theme_id' => 'show-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'https://example.com',
            'category_url' => 'https://example.com/category',
            'article_url' => 'https://example.com/article',
            'style_preference' => 'content_site',
        ]);

        SiteThemeReplicationLog::query()->create([
            'replication_id' => $replication->id,
            'level' => 'info',
            'step' => 'created',
            'message' => 'Task created for test',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => $replication->id]))
            ->assertOk()
            ->assertSee('Show Clone')
            ->assertSee('Task created for test');
    }

    public function test_failed_theme_replication_can_be_retried(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();

        $replication = SiteThemeReplication::query()->create([
            'name' => 'Retry Clone',
            'theme_id' => 'retry-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_FAILED,
            'home_url' => 'https://example.com',
            'category_url' => 'https://example.com/category',
            'article_url' => 'https://example.com/article',
            'style_preference' => 'content_site',
            'error_message' => 'Failed once',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.retry', ['replicationId' => $replication->id]))
            ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => $replication->id]));

        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_QUEUED, $replication->status);
        $this->assertNull($replication->error_message);
        $this->assertDatabaseHas('site_theme_replication_logs', [
            'replication_id' => $replication->id,
            'step' => 'retry',
        ]);
        Queue::assertPushed(
            RunSiteThemeReplicationJob::class,
            fn (RunSiteThemeReplicationJob $job): bool => $job->replicationId === (int) $replication->id
                && $job->queue === 'theme-replication'
        );
    }

    public function test_theme_replication_job_generates_isolated_draft_version(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/' => Http::response($this->referenceHtml('Home Page'), 200),
            'https://example.com/blog' => Http::response($this->referenceHtml('Blog List'), 200),
            'https://example.com/blog/demo' => Http::response($this->referenceHtml('Article Page'), 200),
            'https://example.com/theme.css' => Http::response('body{font-family:Inter, sans-serif;color:#111827}.card{border-radius:12px;padding:24px}.hero{background:#2563eb}', 200),
        ]);

        $replication = SiteThemeReplication::query()->create([
            'name' => 'Draft Clone',
            'theme_id' => 'draft-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
        ]);

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_READY, $replication->status);
        $this->assertSame('passed', $replication->compliance_status);
        $this->assertSame(1, (int) $replication->current_version);
        $this->assertNotEmpty($replication->analysis_json);
        $this->assertNotEmpty($replication->generated_files_json);
        $this->assertNotEmpty($replication->preview_snapshot_json);
        $this->assertDatabaseHas('site_theme_replication_versions', [
            'replication_id' => $replication->id,
            'version' => 1,
        ]);

        $files = (array) ($replication->generated_files_json['files'] ?? []);
        $this->assertNotEmpty($files);
        $paths = collect($files)->pluck('storage_path')->all();
        $this->assertContains("geoflow-theme-replications/{$replication->id}/draft/1/views/manifest.json", $paths);
        $this->assertContains("geoflow-theme-replications/{$replication->id}/draft/1/assets/theme.css", $paths);
        Storage::disk('local')->assertExists("geoflow-theme-replications/{$replication->id}/draft/1/views/home.blade.php");
        Storage::disk('local')->assertExists("geoflow-theme-replications/{$replication->id}/draft/1/assets/theme.js");
        $this->assertDirectoryDoesNotExist(resource_path('views/theme/draft-clone'));

        $css = Storage::disk('local')->get("geoflow-theme-replications/{$replication->id}/draft/1/assets/theme.css");
        $this->assertStringNotContainsString('<script', $css);
        $this->assertStringNotContainsString('https://example.com', $css);
        $this->assertStringNotContainsString('vw', $css);
        $homeBlade = Storage::disk('local')->get("geoflow-theme-replications/{$replication->id}/draft/1/views/home.blade.php");
        $articleBlade = Storage::disk('local')->get("geoflow-theme-replications/{$replication->id}/draft/1/views/article.blade.php");
        $this->assertStringNotContainsString('style=', $homeBlade.$articleBlade);
    }

    public function test_theme_replication_job_skips_internal_stylesheet_links(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtmlWithStylesheet('Reference Page', 'http://internal/theme.css'), 200),
            'http://internal/theme.css' => Http::response('body{color:red}', 200),
        ]);

        $replication = SiteThemeReplication::query()->create([
            'name' => 'Internal CSS Clone',
            'theme_id' => 'internal-css-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
        ]);

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'http://internal/theme.css');
        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_READY, $replication->status);
    }

    public function test_theme_replication_job_revalidates_private_reference_urls_before_fetching(): void
    {
        Storage::fake('local');
        Http::fake([
            '*' => Http::response($this->referenceHtml('Blocked Page'), 200),
        ]);

        $replication = SiteThemeReplication::query()->create([
            'name' => 'Private Runtime Clone',
            'theme_id' => 'private-runtime-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'http://127.0.0.1:8080',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
        ]);

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        Http::assertNothingSent();
        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_FAILED, $replication->status);
        $this->assertSame(__('admin.theme_replication.validation.url_private'), $replication->error_message);
    }

    public function test_theme_replication_job_ignores_non_queued_tasks(): void
    {
        Storage::fake('local');
        Http::fake();

        $replication = SiteThemeReplication::query()->create([
            'name' => 'Ready Clone',
            'theme_id' => 'ready-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_READY,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
            'current_version' => 1,
        ]);

        SiteThemeReplicationVersion::query()->create([
            'replication_id' => (int) $replication->id,
            'version' => 1,
            'prompt_hash' => 'existing',
            'feedback' => null,
            'blueprint_json' => [],
            'files_json' => [],
            'compliance_report_json' => ['passed' => true],
            'draft_views_path' => 'existing/views',
            'draft_assets_path' => 'existing/assets',
        ]);

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        Http::assertNothingSent();
        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_READY, $replication->status);
        $this->assertSame(1, (int) $replication->current_version);
        $this->assertSame(1, SiteThemeReplicationVersion::query()->where('replication_id', $replication->id)->count());
    }

    public function test_ready_theme_replication_preview_routes_render_generated_pages_and_assets(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/' => Http::response($this->referenceHtml('Home Page'), 200),
            'https://example.com/blog' => Http::response($this->referenceHtml('Blog List'), 200),
            'https://example.com/blog/demo' => Http::response($this->referenceHtml('Article Page'), 200),
            'https://example.com/theme.css' => Http::response('body{font-family:Inter, sans-serif;color:#111827}.card{border-radius:12px;padding:24px}.hero{background:#2563eb}', 200),
        ]);

        $category = Category::query()->create([
            'name' => 'Preview Category',
            'slug' => 'preview-category',
            'description' => 'Preview category description',
            'sort_order' => 1,
        ]);
        $author = Author::query()->create([
            'name' => 'Preview Author',
            'email' => 'preview-author@example.com',
        ]);

        Article::query()->create([
            'title' => 'Preview Article Title',
            'slug' => 'preview-article-title',
            'excerpt' => 'Preview article excerpt',
            'content' => "## Preview Section\n\nPreview article body.",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $replication = $this->runReadyReplication('preview-routes-clone');
        File::deleteDirectory(storage_path("framework/geoflow-theme-replications-preview/{$replication->id}"));

        foreach (['home', 'category', 'article'] as $page) {
            $this->actingAs($this->admin(), 'admin')
                ->get(route('admin.site-settings.theme-replications.preview', [
                    'replicationId' => (int) $replication->id,
                    'page' => $page,
                ]))
                ->assertOk()
                ->assertSee('rep-body')
                ->assertSee('Preview Article Title');
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.assets', [
                'replicationId' => (int) $replication->id,
                'assetPath' => 'theme.css',
            ]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('--rep-primary');

        $this->assertDirectoryDoesNotExist(storage_path("framework/geoflow-theme-replications-preview/{$replication->id}"));
        Storage::disk('local')->assertExists("geoflow-theme-replications-preview/{$replication->id}/v1/theme/preview-routes-clone/home.blade.php");
    }

    public function test_ready_theme_replication_detail_shows_preview_modes_and_file_diff(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $replication = $this->runReadyReplication('detail-phase-four-clone');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.button.desktop_preview'))
            ->assertSee(__('admin.theme_replication.button.mobile_preview'))
            ->assertSee(__('admin.theme_replication.section.file_diff'))
            ->assertSee(__('admin.theme_replication.diff.added'))
            ->assertSee('assets/theme.css');
    }

    public function test_ready_theme_replication_can_be_copied_as_new_template(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $replication = $this->runReadyReplication('copy-source-clone');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.copy', ['replicationId' => (int) $replication->id]), [
                'name' => 'Copied Clone',
                'theme_id' => 'copied-clone',
            ])
            ->assertRedirect();

        $copy = SiteThemeReplication::query()->where('theme_id', 'copied-clone')->first();

        $this->assertNotNull($copy);
        $this->assertSame(SiteThemeReplication::STATUS_READY, $copy->status);
        $this->assertSame((string) $replication->theme_id, (string) $copy->base_theme_id);
        $this->assertSame(1, (int) $copy->current_version);
        $this->assertDatabaseHas('site_theme_replication_versions', [
            'replication_id' => (int) $copy->id,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('site_theme_replication_logs', [
            'replication_id' => (int) $copy->id,
            'step' => 'copied',
        ]);
        Storage::disk('local')->assertExists("geoflow-theme-replications/{$copy->id}/draft/1/assets/theme.css");
    }

    public function test_ready_theme_replication_can_queue_feedback_iteration(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $replication = $this->runReadyReplication('iteration-queue-clone');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.iterate', ['replicationId' => (int) $replication->id]), [
                'feedback' => '让首页卡片更紧凑，标题更醒目。',
            ])
            ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]));

        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_ITERATING, $replication->status);
        Queue::assertPushed(
            IterateSiteThemeReplicationJob::class,
            fn (IterateSiteThemeReplicationJob $job): bool => $job->replicationId === (int) $replication->id
                && $job->queue === 'theme-replication'
        );
    }

    public function test_feedback_iteration_generates_next_draft_version(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $replication = $this->runReadyReplication('iteration-version-clone');
        $replication->forceFill(['status' => SiteThemeReplication::STATUS_ITERATING])->save();

        app()->call([new IterateSiteThemeReplicationJob((int) $replication->id, '请让整体更紧凑，标题更醒目。'), 'handle']);

        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_READY, $replication->status);
        $this->assertSame(2, (int) $replication->current_version);
        $this->assertSame(1, (int) $replication->iteration_count);
        $this->assertDatabaseHas('site_theme_replication_versions', [
            'replication_id' => (int) $replication->id,
            'version' => 2,
            'feedback' => '请让整体更紧凑，标题更醒目。',
        ]);
        Storage::disk('local')->assertExists("geoflow-theme-replications/{$replication->id}/draft/2/assets/theme.css");
    }

    public function test_ready_theme_replication_can_be_downloaded_as_package(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $replication = $this->runReadyReplication('package-clone');
        $package = app(ThemeReplicationPackageService::class)->createPackage($replication);

        $this->assertFileExists((string) $package['absolute_path']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open((string) $package['absolute_path']));
        $this->assertNotFalse($zip->locateName('resources/views/theme/package-clone/home.blade.php'));
        $this->assertNotFalse($zip->locateName('public/themes/package-clone/theme.css'));
        $zip->close();
    }

    public function test_ready_theme_replication_can_be_published_to_live_theme_catalog(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! is_writable(resource_path('views/theme')) || ! is_writable(public_path('themes'))) {
            $this->markTestSkipped('Theme directories are not writable in this environment.');
        }

        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $themeId = 'publish-clone-'.strtolower(str_replace('.', '-', uniqid('', true)));
        $viewsPath = resource_path("views/theme/{$themeId}");
        $assetsPath = public_path("themes/{$themeId}");
        File::deleteDirectory($viewsPath);
        File::deleteDirectory($assetsPath);

        try {
            $replication = $this->runReadyReplication($themeId);

            $this->actingAs($this->admin(), 'admin')
                ->post(route('admin.site-settings.theme-replications.publish', ['replicationId' => (int) $replication->id]))
                ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]));

            $replication->refresh();
            $this->assertSame(SiteThemeReplication::STATUS_PUBLISHED, $replication->status);
            $this->assertFileExists($viewsPath.'/manifest.json');
            $this->assertFileExists($viewsPath.'/home.blade.php');
            $this->assertFileExists($assetsPath.'/theme.css');

            $publishedThemeIds = collect(app(SiteThemeCatalog::class)->all())->pluck('id')->all();
            $this->assertContains($themeId, $publishedThemeIds);
        } finally {
            File::deleteDirectory($viewsPath);
            File::deleteDirectory($assetsPath);
        }
    }

    public function test_theme_replication_job_marks_task_failed_when_reference_fetch_fails(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response('not found', 500),
        ]);

        $replication = SiteThemeReplication::query()->create([
            'name' => 'Fetch Failed Clone',
            'theme_id' => 'fetch-failed-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
        ]);

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_FAILED, $replication->status);
        $this->assertStringContainsString('HTTP 500', (string) $replication->error_message);
        $this->assertDatabaseHas('site_theme_replication_logs', [
            'replication_id' => $replication->id,
            'step' => 'failed',
        ]);
    }

    public function test_failed_theme_replication_detail_shows_ai_failure_advice(): void
    {
        $replication = SiteThemeReplication::query()->create([
            'name' => 'AI Failed Clone',
            'theme_id' => 'ai-failed-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_FAILED,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
            'error_message' => 'AI API request failed: HTTP 400',
        ]);

        SiteThemeReplicationLog::query()->create([
            'replication_id' => (int) $replication->id,
            'level' => 'error',
            'step' => 'failed',
            'message' => 'AI API request failed: HTTP 400',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.failure.ai_title'))
            ->assertDontSee(__('admin.theme_replication.failure.fetch_title'));
    }

    public function test_published_theme_replication_can_be_archived_and_draft_files_deleted(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $replication = $this->runReadyReplication('archive-cleanup-clone');
        $replication->forceFill(['status' => SiteThemeReplication::STATUS_PUBLISHED])->save();

        $draftFile = "geoflow-theme-replications/{$replication->id}/draft/1/assets/theme.css";
        Storage::disk('local')->assertExists($draftFile);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.archive', ['replicationId' => (int) $replication->id]))
            ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]));

        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_ARCHIVED, $replication->status);
        $this->assertTrue($replication->isPreviewReady());
        $this->assertDatabaseHas('site_theme_replication_logs', [
            'replication_id' => (int) $replication->id,
            'step' => 'archived',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.preview', [
                'replicationId' => (int) $replication->id,
                'page' => 'home',
            ]))
            ->assertOk();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.theme-replications.delete-drafts', ['replicationId' => (int) $replication->id]))
            ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]));

        Storage::disk('local')->assertMissing($draftFile);
        $replication->refresh();
        $this->assertNull($replication->generated_files_json);
        $this->assertNull($replication->preview_snapshot_json);
        $this->assertFalse($replication->isPreviewReady());
        $this->assertDatabaseHas('site_theme_replication_logs', [
            'replication_id' => (int) $replication->id,
            'step' => 'drafts_deleted',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.empty.draft_files'))
            ->assertSee(__('admin.theme_replication.empty.file_diff'))
            ->assertDontSee('assets/theme.css');
    }

    public function test_theme_replication_compliance_blocks_external_css_urls(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'geoflow-theme-replications/1/draft/1/assets/theme.css',
            '.hero{background-image:url("https://cdn.example.com/bg.jpg")}'
        );

        $report = app(ThemeComplianceGuard::class)->scan([
            'files' => [
                [
                    'path' => 'assets/theme.css',
                    'storage_path' => 'geoflow-theme-replications/1/draft/1/assets/theme.css',
                ],
            ],
        ]);

        $this->assertFalse($report['passed']);
        $this->assertSame('external_css_url', $report['violations'][0]['rule'] ?? null);
    }

    public function test_theme_replication_compliance_blocks_external_imports_and_js_requests(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'geoflow-theme-replications/1/draft/1/assets/theme.css',
            '@import url("https://cdn.example.com/theme.css");'
        );
        Storage::disk('local')->put(
            'geoflow-theme-replications/1/draft/1/assets/theme.js',
            'fetch("https://api.example.com/theme")'
        );

        $report = app(ThemeComplianceGuard::class)->scan([
            'files' => [
                [
                    'path' => 'assets/theme.css',
                    'storage_path' => 'geoflow-theme-replications/1/draft/1/assets/theme.css',
                ],
                [
                    'path' => 'assets/theme.js',
                    'storage_path' => 'geoflow-theme-replications/1/draft/1/assets/theme.js',
                ],
            ],
        ]);

        $rules = collect($report['violations'] ?? [])->pluck('rule')->all();

        $this->assertFalse($report['passed']);
        $this->assertContains('external_css_import', $rules);
        $this->assertContains('external_css_url', $rules);
        $this->assertContains('external_js_request', $rules);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'theme_replication_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid('theme-replication-admin-').'@example.com',
            'display_name' => 'Theme Replication Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function activeChatModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Theme Clone Chat',
            'model_id' => 'theme-clone-chat',
            'model_type' => 'chat',
            'api_key' => 'secret',
            'api_url' => 'https://api.example.com',
            'status' => 'active',
        ]);
    }

    private function referenceHtml(string $title): string
    {
        return $this->referenceHtmlWithStylesheet($title, '/theme.css');
    }

    private function referenceHtmlWithStylesheet(string $title, string $stylesheetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <meta name="description" content="Reference page for theme replication">
    <link rel="stylesheet" href="{$stylesheetUrl}">
</head>
<body>
    <header><nav><a href="/">Home</a><a href="/blog">Blog</a></nav></header>
    <main>
        <section class="hero"><h1>{$title}</h1><p>Structured content example for GEOFlow.</p></section>
        <article class="card"><h2>Example article card</h2><p>Useful content summary.</p></article>
        <aside class="sidebar">Related resources</aside>
    </main>
    <footer>Footer</footer>
</body>
</html>
HTML;
    }

    private function runReadyReplication(string $themeId): SiteThemeReplication
    {
        $replication = SiteThemeReplication::query()->create([
            'name' => 'Ready '.$themeId,
            'theme_id' => $themeId,
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
        ]);

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        return $replication->fresh() ?? $replication;
    }
}
