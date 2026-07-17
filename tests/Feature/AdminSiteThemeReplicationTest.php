<?php

namespace Tests\Feature;

use App\Jobs\IterateSiteThemeReplicationJob;
use App\Jobs\RunSiteThemeReplicationJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationLog;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplication\ThemeComplianceGuard;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackagePathGuard;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackageService;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPublishService;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationStorageGuard;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationStorageLock;
use App\Services\Admin\SiteThemeReplication\ThemeScaffoldWriter;
use App\Services\Admin\SiteThemeReplicationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public function test_docker_queue_workers_listen_to_theme_replication_queue(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $composeFile) {
            $content = File::get(base_path($composeFile));

            $this->assertStringContainsString(
                '--queue=geoflow,distribution,theme-replication,default',
                $content,
                $composeFile.' must consume the queue used by theme replication jobs.'
            );
        }
    }

    public function test_theme_replication_store_returns_validation_error_when_tables_are_missing(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();

        $model = $this->activeChatModel();

        Schema::dropIfExists('site_theme_replication_versions');
        Schema::dropIfExists('site_theme_replication_logs');
        Schema::dropIfExists('site_theme_replications');

        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.site-settings.theme-replications.create'))
            ->post(route('admin.site-settings.theme-replications.store'), [
                'name' => 'Missing Tables Clone',
                'theme_id' => 'missing-tables-clone',
                'base_theme_id' => '',
                'ai_model_id' => $model->id,
                'style_preference' => 'content_site',
                'home_url' => 'https://example.com/',
                'category_url' => 'https://example.com/blog',
                'article_url' => 'https://example.com/blog/demo',
                'compliance_ack' => '1',
            ])
            ->assertRedirect(route('admin.site-settings.theme-replications.create'))
            ->assertSessionHasErrors('theme_replication');

        Queue::assertNotPushed(RunSiteThemeReplicationJob::class);
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

    public function test_theme_replication_detail_shows_persistent_progress_timeline(): void
    {
        $replication = SiteThemeReplication::query()->create([
            'name' => 'Progress Clone',
            'theme_id' => 'progress-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_FETCHING,
            'home_url' => 'https://example.com',
            'category_url' => 'https://example.com/category',
            'article_url' => 'https://example.com/article',
            'style_preference' => 'content_site',
        ]);

        foreach (['created', 'queued', 'fetching'] as $step) {
            SiteThemeReplicationLog::query()->create([
                'replication_id' => $replication->id,
                'level' => 'info',
                'step' => $step,
                'message' => 'Progress step '.$step,
            ]);
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => $replication->id]))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.section.progress'))
            ->assertSee(__('admin.theme_replication.progress.step.fetching'))
            ->assertSee(route('admin.site-settings.theme-replications.status', ['replicationId' => $replication->id], false), false)
            ->assertSee('data-progress-stages', false);
    }

    public function test_theme_replication_status_endpoint_returns_progress_payload(): void
    {
        $replication = SiteThemeReplication::query()->create([
            'name' => 'Status Clone',
            'theme_id' => 'status-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_GENERATING,
            'home_url' => 'https://example.com',
            'category_url' => 'https://example.com/category',
            'article_url' => 'https://example.com/article',
            'style_preference' => 'content_site',
        ]);

        foreach (['created', 'queued', 'fetching', 'extracting', 'analyzing', 'generating'] as $step) {
            SiteThemeReplicationLog::query()->create([
                'replication_id' => $replication->id,
                'level' => 'info',
                'step' => $step,
                'message' => 'Status step '.$step,
            ]);
        }

        $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.site-settings.theme-replications.status', ['replicationId' => $replication->id]))
            ->assertOk()
            ->assertJsonPath('status', SiteThemeReplication::STATUS_GENERATING)
            ->assertJsonPath('current_step', 'generating')
            ->assertJsonPath('terminal', false)
            ->assertJsonPath('stages.5.key', 'generating')
            ->assertJsonPath('logs.0.step', 'generating');
    }

    public function test_failed_iteration_progress_keeps_reference_stage_when_refetching(): void
    {
        $replication = SiteThemeReplication::query()->create([
            'name' => 'Iteration Refetch Clone',
            'theme_id' => 'iteration-refetch-clone',
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_FAILED,
            'home_url' => 'https://example.com',
            'category_url' => 'https://example.com/category',
            'article_url' => 'https://example.com/article',
            'style_preference' => 'content_site',
        ]);

        foreach (['created', 'queued', 'iteration_queued', 'fetching', 'failed'] as $step) {
            SiteThemeReplicationLog::query()->create([
                'replication_id' => $replication->id,
                'level' => $step === 'failed' ? 'error' : 'info',
                'step' => $step,
                'message' => 'Iteration step '.$step,
            ]);
        }

        $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.site-settings.theme-replications.status', ['replicationId' => $replication->id]))
            ->assertOk()
            ->assertJsonPath('current_step', 'fetching')
            ->assertJsonPath('stages.3.key', 'fetching')
            ->assertJsonPath('stages.3.state', 'failed');
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
        $headerBlade = Storage::disk('local')->get("geoflow-theme-replications/{$replication->id}/draft/1/views/partials/header.blade.php");
        $homeNavPosition = strpos($headerBlade, 'data-nav-item="home"');
        $archivePosition = strpos($headerBlade, "route('site.archive')");
        $this->assertNotFalse($homeNavPosition);
        $this->assertNotFalse($archivePosition);
        $this->assertLessThan($archivePosition, $homeNavPosition);
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

        $replication = $this->replicationWithDraftFiles('ready-clone');

        app()->call([new RunSiteThemeReplicationJob((int) $replication->id), 'handle']);

        Http::assertNothingSent();
        $replication->refresh();
        $this->assertSame(SiteThemeReplication::STATUS_READY, $replication->status);
        $this->assertSame(1, (int) $replication->current_version);
        $this->assertSame(1, SiteThemeReplicationVersion::query()->where('replication_id', $replication->id)->count());
    }

    public function test_ready_theme_replication_preview_uses_only_trusted_static_content(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/' => Http::response($this->referenceHtml('Home Page'), 200),
            'https://example.com/blog' => Http::response($this->referenceHtml('Blog List'), 200),
            'https://example.com/blog/demo' => Http::response($this->referenceHtml('Article Page'), 200),
            'https://example.com/theme.css' => Http::response('body{font-family:Inter, sans-serif;color:#111827}.card{border-radius:12px;padding:24px}.hero{background:#2563eb}', 200),
        ]);

        $replication = $this->runReadyReplication('preview-routes-clone');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $marker = storage_path('framework/testing/theme-replication-preview-executed.php');
        File::delete($marker);
        Storage::disk('local')->put(
            trim((string) $version->draft_views_path, '/').'/home.blade.php',
            "@php(file_put_contents('{$marker}', 'executed')) <script src=\"https://external.example/x.js\"></script> <img src=x onerror=alert(1)> {!! 'raw-danger' !!}"
        );
        Storage::disk('local')->put(
            trim((string) $version->draft_assets_path, '/').'/theme.css',
            '@import url("https://external.example/theme.css"); .hero{background:url("https://external.example/x.png")}'
        );
        File::deleteDirectory(storage_path("framework/geoflow-theme-replications-preview/{$replication->id}"));
        Storage::disk('local')->deleteDirectory("geoflow-theme-replications-preview/{$replication->id}");

        $csp = "default-src 'none'; style-src 'unsafe-inline'; script-src 'none'; connect-src 'none'; img-src 'none'; font-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox";

        foreach (['home', 'category', 'article'] as $page) {
            $this->actingAs($this->admin(), 'admin')
                ->get(route('admin.site-settings.theme-replications.preview', [
                    'replicationId' => (int) $replication->id,
                    'page' => $page,
                ]))
                ->assertOk()
                ->assertHeader('Content-Security-Policy', $csp)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('data-safe-theme-preview', false)
                ->assertSee('data-preview-page="'.$page.'"', false)
                ->assertDontSee('@php', false)
                ->assertDontSee('{!!', false)
                ->assertDontSee('<script', false)
                ->assertDontSee('onerror', false)
                ->assertDontSee('@import', false)
                ->assertDontSee('raw-danger', false)
                ->assertDontSee('external.example', false)
                ->assertDontSee('http://', false)
                ->assertDontSee('https://', false);
        }

        $assetUrl = '/'.trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/')
            .'/site-settings/theme-replications/'.$replication->id.'/assets/theme.css';
        $this->actingAs($this->admin(), 'admin')
            ->get($assetUrl)
            ->assertNotFound();

        $this->assertFileDoesNotExist($marker);
        $this->assertDirectoryDoesNotExist(storage_path("framework/geoflow-theme-replications-preview/{$replication->id}"));
        Storage::disk('local')->assertMissing("geoflow-theme-replications-preview/{$replication->id}");
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
            ->assertSee(__('admin.theme_replication.button.make_package'))
            ->assertSee(__('admin.theme_replication.deployment.package_only_hint'))
            ->assertSee('sandbox=""', false)
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
        $this->assertSame(
            [(string) $package['relative_path']],
            Storage::disk('local')->allFiles(dirname((string) $package['relative_path']))
        );
        $zip = new ZipArchive;
        $this->assertTrue($zip->open((string) $package['absolute_path']));
        $this->assertNotFalse($zip->locateName('resources/views/theme/package-clone/home.blade.php'));
        $this->assertNotFalse($zip->locateName('public/themes/package-clone/theme.css'));

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string) $zip->getNameIndex($index);
            $this->assertStringNotContainsString('\\', $entry);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $entry);
            $this->assertDoesNotMatchRegularExpression('/\A(?:\/|[A-Za-z]:)/', $entry);
            $this->assertTrue(
                str_starts_with($entry, 'resources/views/theme/package-clone/')
                || str_starts_with($entry, 'public/themes/package-clone/')
            );
            foreach (explode('/', $entry) as $segment) {
                $this->assertNotContains($segment, ['', '.', '..']);
            }
        }

        $zip->close();
    }

    public function test_theme_replication_package_matches_the_verified_manifest_exactly(): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('verified-manifest-theme');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $manifestFiles = (array) (((array) $version->files_json)['files'] ?? []);
        $package = app(ThemeReplicationPackageService::class)->createPackage($replication);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open((string) $package['absolute_path']));
        $this->assertSame(count($manifestFiles), $zip->numFiles);

        foreach ($manifestFiles as $file) {
            $path = (string) $file['path'];
            $entry = str_starts_with($path, 'views/')
                ? 'resources/views/theme/verified-manifest-theme/'.substr($path, strlen('views/'))
                : 'public/themes/verified-manifest-theme/'.substr($path, strlen('assets/'));
            $content = $zip->getFromName($entry);

            $this->assertIsString($content);
            $this->assertSame((int) $file['bytes'], strlen($content));
            $this->assertSame((string) $file['checksum'], hash('sha256', $content));
        }

        $zip->close();
    }

    #[DataProvider('packageCapabilityStatuses')]
    public function test_package_capability_is_consistent_across_model_ui_controller_and_service(
        string $status,
        bool $expected,
    ): void {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('package-capability-theme');
        $replication->forceFill(['status' => $status])->save();

        $this->assertTrue(method_exists($replication, 'canPackage'));
        $this->assertSame($expected, $replication->canPackage());

        $showResponse = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]));
        $downloadUrl = route('admin.site-settings.theme-replications.package', ['replicationId' => (int) $replication->id]);

        if ($expected) {
            $showResponse->assertOk()->assertSee($downloadUrl, false);
            $this->actingAs($this->admin(), 'admin')
                ->get($downloadUrl)
                ->assertDownload('package-capability-theme-v1.zip');
            $this->assertFileExists(
                Storage::disk('local')->path('geoflow-theme-replications/'.(int) $replication->id.'/packages/package-capability-theme-v1.zip')
            );

            return;
        }

        $showResponse->assertOk()->assertDontSee($downloadUrl, false);
        $this->actingAs($this->admin(), 'admin')
            ->get($downloadUrl)
            ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertSessionHasErrors();
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('geoflow-theme-replications/'.(int) $replication->id.'/packages')
        );
    }

    /**
     * @return array<string, array{string,bool}>
     */
    public static function packageCapabilityStatuses(): array
    {
        return [
            'ready with verified drafts' => [SiteThemeReplication::STATUS_READY, true],
            'legacy published with verified drafts' => [SiteThemeReplication::STATUS_PUBLISHED, true],
            'archived with verified drafts' => [SiteThemeReplication::STATUS_ARCHIVED, true],
            'failed task' => [SiteThemeReplication::STATUS_FAILED, false],
            'queued task' => [SiteThemeReplication::STATUS_QUEUED, false],
        ];
    }

    #[DataProvider('invalidPackageCapabilityMetadata')]
    public function test_package_capability_requires_current_safe_draft_metadata(string $mode): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('package-contract-theme');
        match ($mode) {
            'current_version' => $replication->forceFill(['current_version' => 0])->save(),
            'manifest' => $replication->forceFill(['generated_files_json' => null])->save(),
            'compliance_status' => $replication->forceFill(['compliance_status' => 'failed'])->save(),
            'compliance_report' => $replication->forceFill(['compliance_report_json' => ['passed' => 'true']])->save(),
        };

        $this->assertTrue(method_exists($replication, 'canPackage'));
        $this->assertFalse($replication->canPackage());

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertOk()
            ->assertDontSee(
                route('admin.site-settings.theme-replications.package', ['replicationId' => (int) $replication->id]),
                false,
            )
            ->assertDontSee(
                route('admin.site-settings.theme-replications.publish', ['replicationId' => (int) $replication->id]),
                false,
            );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPackageCapabilityMetadata(): array
    {
        return [
            'missing current version' => ['current_version'],
            'deleted draft manifest' => ['manifest'],
            'failed compliance status' => ['compliance_status'],
            'non boolean compliance report' => ['compliance_report'],
        ];
    }

    public function test_theme_replication_package_cleans_final_and_old_packages_when_logging_fails(): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('package-log-failure-theme');
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);
        $loggingService = $this->createMock(SiteThemeReplicationService::class);
        $loggingService->expects($this->once())
            ->method('log')
            ->willThrowException(new RuntimeException('package log failed'));
        $packageService = new ThemeReplicationPackageService(
            $loggingService,
            app(ThemeReplicationPackagePathGuard::class),
            app(ThemeReplicationStorageGuard::class),
            app(ThemeReplicationStorageLock::class),
        );

        try {
            $packageService->createPackage($replication);
            $this->fail('Package creation accepted a failed audit log write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('package log failed', $exception->getMessage());
        }

        $packageDirectory = 'geoflow-theme-replications/'.(int) $replication->id.'/packages';
        $this->assertSame(
            [(string) $existingPackage['relative_path']],
            Storage::disk('local')->allFiles($packageDirectory),
        );
    }

    public function test_concurrent_package_request_times_out_without_deleting_the_existing_final_package(): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('package-lock-theme');
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $existingHash = hash_file('sha256', (string) $existingPackage['absolute_path']);
        $lockDirectory = 'geoflow-theme-replication-package-locks';
        Storage::disk('local')->makeDirectory($lockDirectory);
        $lockHandle = fopen(
            Storage::disk('local')->path($lockDirectory.'/'.(int) $replication->id.'.lock'),
            'c+b',
        );
        $this->assertIsResource($lockHandle);
        $this->assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));
        config()->set('geoflow.theme_replication_package_lock_timeout_milliseconds', 25);

        try {
            $exception = null;
            try {
                app(ThemeReplicationPackageService::class)->createPackage($replication);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame(__('admin.theme_replication.error.package_create_failed'), $exception->getMessage());
            $this->assertFileExists((string) $existingPackage['absolute_path']);
            $this->assertSame($existingHash, hash_file('sha256', (string) $existingPackage['absolute_path']));
            $this->assertSame(
                [(string) $existingPackage['relative_path']],
                Storage::disk('local')->allFiles(dirname((string) $existingPackage['relative_path']))
            );
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        $retriedPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $retriedPackage['absolute_path']);
        $this->assertSame(
            [(string) $retriedPackage['relative_path']],
            Storage::disk('local')->allFiles(dirname((string) $retriedPackage['relative_path']))
        );
    }

    #[DataProvider('invalidPackageBoundaries')]
    public function test_theme_replication_package_rejects_invalid_state_and_manifest_boundaries(string $mode): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('manifest-boundary-theme');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);
        $manifest = (array) $version->files_json;
        $files = (array) ($manifest['files'] ?? []);

        match ($mode) {
            'status' => $replication->forceFill(['status' => SiteThemeReplication::STATUS_FAILED])->save(),
            'replication_compliance' => $replication->forceFill(['compliance_status' => 'failed'])->save(),
            'replication_report_type' => $replication->forceFill(['compliance_report_json' => ['passed' => 'true']])->save(),
            'version_compliance' => $version->forceFill(['compliance_report_json' => ['passed' => false]])->save(),
            'version_report_type' => $version->forceFill(['compliance_report_json' => ['passed' => 1]])->save(),
            'current_version' => $replication->forceFill(['current_version' => 2])->save(),
            'replication_manifest' => $replication->forceFill(['generated_files_json' => ['files' => []]])->save(),
            'empty_manifest' => $version->forceFill(['files_json' => []])->save(),
            'empty_files' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => []])])->save(),
            'malformed_files' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => 'invalid'])])->save(),
            'malformed_record' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [['path' => 'views/home.blade.php']]])])->save(),
            'extra_record_key' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['extra' => true]), $files[1]]])])->save(),
            'unsafe_path' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['path' => 'views/../secret.php']), $files[1]]])])->save(),
            'wrong_prefix' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['path' => 'other/home.blade.php']), $files[1]]])])->save(),
            'storage_path' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['storage_path' => 'private/secrets/home.blade.php']), $files[1]]])])->save(),
            'bytes_type' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['bytes' => (string) $files[0]['bytes']]), $files[1]]])])->save(),
            'negative_bytes' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['bytes' => -1]), $files[1]]])])->save(),
            'bytes_mismatch' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['bytes' => (int) $files[0]['bytes'] + 1]), $files[1]]])])->save(),
            'checksum_format' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['checksum' => str_repeat('A', 64)]), $files[1]]])])->save(),
            'checksum_mismatch' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [array_merge($files[0], ['checksum' => str_repeat('0', 64)]), $files[1]]])])->save(),
            'duplicate' => $version->forceFill(['files_json' => array_merge($manifest, ['files' => [$files[0], $files[1], $files[0]]])])->save(),
        };

        $this->assertThemePackageRejectedAndCleaned($replication);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPackageBoundaries(): array
    {
        return [
            'replication status is not ready' => ['status'],
            'replication compliance failed' => ['replication_compliance'],
            'replication compliance report passed is not boolean' => ['replication_report_type'],
            'version compliance failed' => ['version_compliance'],
            'version compliance report passed is not boolean' => ['version_report_type'],
            'current version differs from latest' => ['current_version'],
            'replication manifest differs from current version' => ['replication_manifest'],
            'manifest is empty' => ['empty_manifest'],
            'manifest files are empty' => ['empty_files'],
            'manifest files are malformed' => ['malformed_files'],
            'manifest record is malformed' => ['malformed_record'],
            'manifest record contains an extra key' => ['extra_record_key'],
            'manifest path contains traversal' => ['unsafe_path'],
            'manifest path has an unknown prefix' => ['wrong_prefix'],
            'manifest storage path is not canonical' => ['storage_path'],
            'manifest bytes are not an integer' => ['bytes_type'],
            'manifest bytes are negative' => ['negative_bytes'],
            'manifest bytes differ from the file' => ['bytes_mismatch'],
            'manifest checksum is not lowercase sha256' => ['checksum_format'],
            'manifest checksum differs from the file' => ['checksum_mismatch'],
            'manifest contains duplicate records' => ['duplicate'],
        ];
    }

    #[DataProvider('invalidCanonicalFileSets')]
    public function test_theme_replication_package_rejects_file_set_changes_after_scan(string $mode): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('changed-file-set-theme');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);
        $manifestFiles = (array) (((array) $version->files_json)['files'] ?? []);
        $viewsFile = (string) $manifestFiles[0]['storage_path'];
        $assetsFile = (string) $manifestFiles[1]['storage_path'];

        match ($mode) {
            'changed_content' => Storage::disk('local')->put(
                $viewsFile,
                str_repeat('X', (int) $manifestFiles[0]['bytes'])
            ),
            'added_file' => Storage::disk('local')->put(
                dirname($viewsFile).'/unscanned.blade.php',
                'unscanned'
            ),
            'missing_file' => Storage::disk('local')->delete($assetsFile),
            'directory' => tap(Storage::disk('local')->delete($assetsFile), fn () => File::makeDirectory(Storage::disk('local')->path($assetsFile))),
        };

        $this->assertThemePackageRejectedAndCleaned($replication);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCanonicalFileSets(): array
    {
        return [
            'content changed after scan' => ['changed_content'],
            'unscanned file added' => ['added_file'],
            'scanned file missing' => ['missing_file'],
            'manifest path became a directory' => ['directory'],
        ];
    }

    public function test_theme_replication_package_rejects_symbolic_links(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are not available on this platform.');
        }

        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('symlink-file-theme');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);
        $manifestFiles = (array) (((array) $version->files_json)['files'] ?? []);
        $linkedFile = Storage::disk('local')->path((string) $manifestFiles[0]['storage_path']);
        $secretPath = Storage::disk('local')->path('private/symlink-secret.txt');
        Storage::disk('local')->put('private/symlink-secret.txt', str_repeat('S', (int) $manifestFiles[0]['bytes']));
        File::delete($linkedFile);

        if (! @symlink($secretPath, $linkedFile)) {
            $this->markTestSkipped('The filesystem does not permit symbolic links.');
        }

        $this->assertThemePackageRejectedAndCleaned($replication);
    }

    public function test_theme_replication_package_rejects_a_symbolic_link_package_directory(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are not available on this platform.');
        }

        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('symlink-package-directory-theme');
        $externalDirectory = Storage::disk('local')->path('private/external-package-directory');
        File::ensureDirectoryExists($externalDirectory);
        $sentinel = $externalDirectory.'/sentinel.txt';
        File::put($sentinel, 'preserve-me');
        $packageDirectory = Storage::disk('local')->path(
            'geoflow-theme-replications/'.(int) $replication->id.'/packages'
        );

        if (! @symlink($externalDirectory, $packageDirectory)) {
            $this->markTestSkipped('The filesystem does not permit symbolic links.');
        }

        try {
            app(ThemeReplicationPackageService::class)->createPackage($replication);
            $this->fail('Package creation accepted a symbolic link package directory.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_replication.error.invalid_package_path'), $exception->getMessage());
        } finally {
            if (is_link($packageDirectory)) {
                unlink($packageDirectory);
            }
        }

        $this->assertFileExists($sentinel);
        $this->assertSame('preserve-me', File::get($sentinel));
        $this->assertSame(['sentinel.txt'], array_values(array_map('basename', File::files($externalDirectory))));
    }

    public function test_theme_replication_writer_rejects_a_symbolic_link_draft_directory(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are not available on this platform.');
        }

        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('symlink-writer-directory-theme');
        $externalDirectory = Storage::disk('local')->path('private/external-writer-directory');
        File::ensureDirectoryExists($externalDirectory);
        $sentinel = $externalDirectory.'/sentinel.txt';
        File::put($sentinel, 'preserve-me');
        $draftDirectory = Storage::disk('local')->path(
            'geoflow-theme-replications/'.(int) $replication->id.'/draft/2'
        );

        if (! @symlink($externalDirectory, $draftDirectory)) {
            $this->markTestSkipped('The filesystem does not permit symbolic links.');
        }

        try {
            app(ThemeScaffoldWriter::class)->write($replication, 2, []);
            $this->fail('Theme writer accepted a symbolic link draft directory.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_replication.error.invalid_package_path'), $exception->getMessage());
        } finally {
            if (is_link($draftDirectory)) {
                unlink($draftDirectory);
            }
        }

        $this->assertFileExists($sentinel);
        $this->assertSame(['sentinel.txt'], array_values(array_map('basename', File::files($externalDirectory))));
    }

    public function test_delete_drafts_rejects_a_symbolic_link_replication_root(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are not available on this platform.');
        }

        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('symlink-delete-directory-theme');
        $replication->forceFill(['status' => SiteThemeReplication::STATUS_ARCHIVED])->save();
        $replicationRoot = Storage::disk('local')->path('geoflow-theme-replications/'.(int) $replication->id);
        $relocatedRoot = Storage::disk('local')->path('private/relocated-replication-root');
        File::ensureDirectoryExists(dirname($relocatedRoot));
        File::moveDirectory($replicationRoot, $relocatedRoot);
        $externalDirectory = Storage::disk('local')->path('private/external-delete-directory');
        File::ensureDirectoryExists($externalDirectory);
        $sentinel = $externalDirectory.'/sentinel.txt';
        File::put($sentinel, 'preserve-me');

        if (! @symlink($externalDirectory, $replicationRoot)) {
            $this->markTestSkipped('The filesystem does not permit symbolic links.');
        }

        try {
            app(SiteThemeReplicationService::class)->deleteDrafts($replication);
            $this->fail('Draft deletion accepted a symbolic link replication root.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_replication.error.invalid_package_path'), $exception->getMessage());
        } finally {
            if (is_link($replicationRoot)) {
                unlink($replicationRoot);
            }
        }

        $this->assertFileExists($sentinel);
        $this->assertSame('preserve-me', File::get($sentinel));
    }

    public function test_delete_drafts_preserves_an_existing_review_package(): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('preserved-review-package-theme');
        $package = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $replication->forceFill(['status' => SiteThemeReplication::STATUS_ARCHIVED])->save();

        app(SiteThemeReplicationService::class)->deleteDrafts($replication);

        $this->assertFileExists((string) $package['absolute_path']);
        Storage::disk('local')->assertMissing('geoflow-theme-replications/'.(int) $replication->id.'/draft');
    }

    public function test_delete_drafts_uses_the_same_replication_lock_as_package_creation(): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('delete-drafts-lock-theme');
        $replication->forceFill(['status' => SiteThemeReplication::STATUS_ARCHIVED])->save();
        $lockDirectory = 'geoflow-theme-replication-package-locks';
        Storage::disk('local')->makeDirectory($lockDirectory);
        $lockHandle = fopen(
            Storage::disk('local')->path($lockDirectory.'/'.(int) $replication->id.'.lock'),
            'c+b',
        );
        $this->assertIsResource($lockHandle);
        $this->assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));
        config()->set('geoflow.theme_replication_package_lock_timeout_milliseconds', 25);

        try {
            $this->expectException(RuntimeException::class);
            app(SiteThemeReplicationService::class)->deleteDrafts($replication);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            Storage::disk('local')->assertExists(
                'geoflow-theme-replications/'.(int) $replication->id.'/draft/1/views/home.blade.php'
            );
        }
    }

    public function test_theme_replication_package_rejects_a_symbolic_link_in_the_canonical_root_chain(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are not available on this platform.');
        }

        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('symlink-root-theme');
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);
        $draftParent = Storage::disk('local')->path('geoflow-theme-replications/'.(int) $replication->id.'/draft');
        $relocatedDraft = Storage::disk('local')->path('private/relocated-draft');
        File::ensureDirectoryExists(dirname($relocatedDraft));
        File::moveDirectory($draftParent, $relocatedDraft);

        if (! @symlink($relocatedDraft, $draftParent)) {
            $this->markTestSkipped('The filesystem does not permit symbolic links.');
        }

        $this->assertThemePackageRejectedAndCleaned($replication);
    }

    #[DataProvider('invalidPackageLimits')]
    public function test_theme_replication_package_enforces_fail_closed_resource_limits(
        string $configKey,
        mixed $value,
    ): void {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('package-limit-theme');
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);
        config()->set($configKey, $value);

        $this->assertThemePackageRejectedAndCleaned($replication);
    }

    /**
     * @return array<string, array{string,mixed}>
     */
    public static function invalidPackageLimits(): array
    {
        return [
            'file count limit' => ['geoflow.theme_replication_package_max_files', 1],
            'per file byte limit' => ['geoflow.theme_replication_package_max_file_bytes', 1],
            'total byte limit' => ['geoflow.theme_replication_package_max_total_bytes', 1],
            'zero file count configuration' => ['geoflow.theme_replication_package_max_files', 0],
            'negative per file configuration' => ['geoflow.theme_replication_package_max_file_bytes', -1],
            'non numeric total configuration' => ['geoflow.theme_replication_package_max_total_bytes', 'unlimited'],
        ];
    }

    #[DataProvider('invalidPackageThemeIds')]
    public function test_theme_replication_package_rejects_invalid_database_theme_id_before_creating_zip(string $themeId): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles($themeId);
        $root = 'geoflow-theme-replications/'.(int) $replication->id;

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(__('admin.theme_replication.validation.theme_id_invalid'));

            app(ThemeReplicationPackageService::class)->createPackage($replication);
        } finally {
            $this->assertSame([], Storage::disk('local')->allFiles($root.'/packages'));
            Storage::disk('local')->deleteDirectory($root);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPackageThemeIds(): array
    {
        return [
            'parent slash' => ['../escape'],
            'parent backslash' => ['..\\..\\escape'],
            'absolute' => ['/absolute'],
            'drive path' => ['C:\\escape'],
            'control' => ["evil\nname"],
            'unicode confusable' => ['ｅｖｉｌ'],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 81)],
        ];
    }

    public function test_theme_replication_package_rejects_abnormal_storage_filename_and_cleans_partial_zip(): void
    {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('safe-package-theme');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);

        $unsafePath = trim((string) $version->draft_views_path, '/').'/C:/escape.blade.php';
        Storage::disk('local')->put($unsafePath, 'unsafe');
        $this->assertContains($unsafePath, Storage::disk('local')->allFiles((string) $version->draft_views_path));

        try {
            app(ThemeReplicationPackageService::class)->createPackage($replication);
            $this->fail('Package creation accepted an abnormal storage filename.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_replication.error.invalid_package_path'), $exception->getMessage());
        }

        $packageDirectory = 'geoflow-theme-replications/'.(int) $replication->id.'/packages';
        $this->assertSame(
            [(string) $existingPackage['relative_path']],
            Storage::disk('local')->allFiles($packageDirectory),
        );
    }

    #[DataProvider('mismatchedDraftRoots')]
    public function test_theme_replication_package_rejects_database_draft_roots_outside_canonical_task_version(
        string $viewsMode,
        string $assetsMode,
    ): void {
        Storage::fake('local');

        $replication = $this->replicationWithDraftFiles('canonical-root-theme');
        $version = $replication->versions()->latest('version')->firstOrFail();
        $existingPackage = app(ThemeReplicationPackageService::class)->createPackage($replication);
        $this->assertFileExists((string) $existingPackage['absolute_path']);

        $canonicalRoot = 'geoflow-theme-replications/'.(int) $replication->id.'/draft/1';
        $viewsPath = match ($viewsMode) {
            'canonical' => $canonicalRoot.'/views',
            'external' => 'private/secrets',
            'other_replication' => 'geoflow-theme-replications/999/draft/1/views',
            'other_version' => 'geoflow-theme-replications/'.(int) $replication->id.'/draft/2/views',
            'leading_slash' => '/'.$canonicalRoot.'/views',
            'trailing_slash' => $canonicalRoot.'/views/',
            'unicode_confusable' => 'geoflow‐theme‐replications/'.(int) $replication->id.'/draft/1/views',
            'assets' => $canonicalRoot.'/assets',
        };
        $assetsPath = match ($assetsMode) {
            'canonical' => $canonicalRoot.'/assets',
            'views' => $canonicalRoot.'/views',
        };

        $secretPath = trim($viewsPath, '/').'/secret.txt';
        Storage::disk('local')->put($secretPath, 'must-not-enter-package');
        $version->forceFill([
            'draft_views_path' => $viewsPath,
            'draft_assets_path' => $assetsPath,
        ])->save();

        try {
            app(ThemeReplicationPackageService::class)->createPackage($replication);
            $this->fail('Package creation accepted a draft root outside the canonical task version.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_replication.error.invalid_package_path'), $exception->getMessage());
        }

        $packageDirectory = 'geoflow-theme-replications/'.(int) $replication->id.'/packages';
        $this->assertSame(
            [(string) $existingPackage['relative_path']],
            Storage::disk('local')->allFiles($packageDirectory),
        );
        Storage::disk('local')->assertExists($secretPath);
        $this->assertSame('must-not-enter-package', Storage::disk('local')->get($secretPath));
    }

    /**
     * @return array<string, array{string,string}>
     */
    public static function mismatchedDraftRoots(): array
    {
        return [
            'external valid directory' => ['external', 'canonical'],
            'another replication' => ['other_replication', 'canonical'],
            'another version' => ['other_version', 'canonical'],
            'leading slash' => ['leading_slash', 'canonical'],
            'trailing slash' => ['trailing_slash', 'canonical'],
            'unicode confusable path' => ['unicode_confusable', 'canonical'],
            'views and assets swapped' => ['assets', 'views'],
        ];
    }

    public function test_ready_theme_replication_publish_only_creates_a_review_package(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response($this->referenceHtml('Reference Page'), 200),
        ]);

        $themeId = 'package-only-publish-'.strtolower(str_replace('.', '-', uniqid('', true)));
        $replication = $this->runReadyReplication($themeId);
        $liveViewsPath = resource_path('views/theme/'.$themeId);
        $liveAssetsPath = public_path('themes/'.$themeId);
        $viewsBefore = $this->directorySnapshot(resource_path('views/theme'));
        $assetsBefore = $this->directorySnapshot(public_path('themes'));

        try {
            $result = app(ThemeReplicationPublishService::class)->publish($replication);

            $this->assertSame('package', $result['mode']);
            $this->assertArrayHasKey('package', $result);
            $this->assertFileExists((string) ($result['package']['absolute_path'] ?? ''));
            $this->assertSame($viewsBefore, $this->directorySnapshot(resource_path('views/theme')));
            $this->assertSame($assetsBefore, $this->directorySnapshot(public_path('themes')));
            $this->assertDirectoryDoesNotExist($liveViewsPath);
            $this->assertDirectoryDoesNotExist($liveAssetsPath);

            $replication->refresh();
            $this->assertSame(SiteThemeReplication::STATUS_READY, $replication->status);
            $this->assertNull($replication->published_theme_path);
            $this->assertNull($replication->published_asset_path);
            $this->assertNull($replication->published_at);
            $this->assertDatabaseHas('site_theme_replication_logs', [
                'replication_id' => (int) $replication->id,
                'step' => 'package_created',
            ]);
        } finally {
            File::deleteDirectory($liveViewsPath);
            File::deleteDirectory($liveAssetsPath);
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
        $this->assertTrue(method_exists($replication, 'canPackage'));
        $this->assertTrue($replication->canPackage());
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
        $this->assertFalse($replication->canPackage());
        $this->assertDatabaseHas('site_theme_replication_logs', [
            'replication_id' => (int) $replication->id,
            'step' => 'drafts_deleted',
        ]);

        $packageUrl = route('admin.site-settings.theme-replications.package', ['replicationId' => (int) $replication->id]);
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertOk()
            ->assertSee(__('admin.theme_replication.empty.draft_files'))
            ->assertSee(__('admin.theme_replication.empty.file_diff'))
            ->assertDontSee('assets/theme.css')
            ->assertDontSee($packageUrl, false);
        $this->actingAs($this->admin(), 'admin')
            ->get($packageUrl)
            ->assertRedirect(route('admin.site-settings.theme-replications.show', ['replicationId' => (int) $replication->id]))
            ->assertSessionHasErrors();
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

    public function test_theme_replication_production_code_has_no_generated_view_execution_or_live_publish_write_path(): void
    {
        $routes = File::get(base_path('routes/web.php'));
        $previewRenderer = File::get(app_path('Services/Admin/SiteThemeReplication/ThemePreviewRenderer.php'));
        $publishService = File::get(app_path('Services/Admin/SiteThemeReplication/ThemeReplicationPublishService.php'));

        $this->assertStringNotContainsString('theme-replications.assets', $routes);
        $this->assertStringNotContainsString('View::addLocation', $previewRenderer);
        $this->assertStringNotContainsString('Blade::render', $previewRenderer);
        $this->assertStringNotContainsString('draft_views_path', $previewRenderer);
        $this->assertStringNotContainsString('draft_assets_path', $previewRenderer);

        foreach (['resource_path', 'public_path', 'File::', 'Storage::', 'can_publish_directly', "'mode' => 'direct'"] as $unsafeCode) {
            $this->assertStringNotContainsString($unsafeCode, $publishService);
        }
    }

    /**
     * @return array<string, string>
     */
    private function directorySnapshot(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $snapshot = [];
        foreach (File::allFiles($path) as $file) {
            $snapshot[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function replicationWithDraftFiles(string $themeId): SiteThemeReplication
    {
        $contents = [
            'views/home.blade.php' => '<main>Safe draft</main>',
            'assets/theme.css' => 'body { color: #111827; }',
        ];
        $replication = SiteThemeReplication::query()->create([
            'name' => 'Package boundary test',
            'theme_id' => $themeId,
            'ai_model_id' => $this->activeChatModel()->id,
            'status' => SiteThemeReplication::STATUS_READY,
            'home_url' => 'https://example.com/',
            'category_url' => 'https://example.com/blog',
            'article_url' => 'https://example.com/blog/demo',
            'style_preference' => 'content_site',
            'current_version' => 1,
            'compliance_status' => 'passed',
            'compliance_report_json' => ['passed' => true],
        ]);

        $root = 'geoflow-theme-replications/'.(int) $replication->id.'/draft/1';
        foreach ($contents as $path => $content) {
            Storage::disk('local')->put($root.'/'.$path, $content);
        }
        $manifest = $this->draftManifest($root, $contents);

        SiteThemeReplicationVersion::query()->create([
            'replication_id' => (int) $replication->id,
            'version' => 1,
            'prompt_hash' => 'package-boundary',
            'feedback' => null,
            'blueprint_json' => [],
            'files_json' => $manifest,
            'compliance_report_json' => ['passed' => true],
            'draft_views_path' => $root.'/views',
            'draft_assets_path' => $root.'/assets',
        ]);

        $replication->forceFill(['generated_files_json' => $manifest])->save();

        return $replication;
    }

    /**
     * @param  array<string, string>  $contents
     * @return array<string, mixed>
     */
    private function draftManifest(string $root, array $contents): array
    {
        $files = [];
        foreach ($contents as $path => $content) {
            $files[] = [
                'path' => $path,
                'storage_path' => $root.'/'.$path,
                'bytes' => strlen($content),
                'checksum' => hash('sha256', $content),
            ];
        }

        return [
            'root_path' => $root,
            'views_path' => $root.'/views',
            'assets_path' => $root.'/assets',
            'files' => $files,
            'written_at' => now()->toIso8601String(),
        ];
    }

    private function assertThemePackageRejectedAndCleaned(SiteThemeReplication $replication): void
    {
        $packageDirectory = 'geoflow-theme-replications/'.(int) $replication->id.'/packages';
        $existingFiles = Storage::disk('local')->allFiles($packageDirectory);

        try {
            app(ThemeReplicationPackageService::class)->createPackage($replication->fresh() ?? $replication);
            $this->fail('Package creation accepted an invalid security boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_replication.error.invalid_package_path'), $exception->getMessage());
        }

        $this->assertSame($existingFiles, Storage::disk('local')->allFiles($packageDirectory));
        foreach (Storage::disk('local')->allFiles($packageDirectory) as $path) {
            $this->assertFalse(str_ends_with($path, '.tmp'));
        }
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'theme_replication_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid('theme-replication-admin-').'@example.com',
            'display_name' => 'Theme Replication Admin',
            'role' => 'super_admin',
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
