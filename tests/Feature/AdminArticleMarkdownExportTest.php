<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleMarkdownExportService;
use App\Support\AdminUiRegistry;
use App\Support\AdminWeb;
use Illuminate\Console\Scheduling\Schedule as ScheduleManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AdminArticleMarkdownExportTest extends TestCase
{
    use RefreshDatabase;

    private string $exportRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportRoot = storage_path('framework/testing/admin-article-exports-'.Str::uuid());
        $this->app->instance(ArticleMarkdownExportService::class, new ArticleMarkdownExportService(
            exportRoot: $this->exportRoot,
        ));
        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->exportRoot);

        parent::tearDown();
    }

    public function test_article_list_exposes_markdown_export_only_outside_trash(): void
    {
        $admin = $this->admin('export-page');
        $article = $this->article();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('value="export_markdown" data-article-batch-export-option disabled', false)
            ->assertSee('data-article-batch-export', false)
            ->assertSee(route('admin.articles.batch.export-markdown.prepare', absolute: false), false);

        $article->delete();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['trashed' => 1]))
            ->assertOk()
            ->assertDontSee('value="export_markdown"', false)
            ->assertDontSee('data-article-batch-export', false);

        $this->assertSame(
            'download',
            app(AdminUiRegistry::class)->routeClassification('admin.articles.batch.export-markdown.download'),
        );
    }

    public function test_admin_can_prepare_and_repeat_a_signed_download_owned_by_their_account(): void
    {
        $admin = $this->admin('export-owner');
        $otherAdmin = $this->admin('export-other');
        $first = $this->article(['title' => '第一篇导出文章', 'content' => '第一篇正文']);
        $second = $this->article(['title' => '第二篇导出文章', 'content' => '第二篇正文']);
        $firstUpdatedAt = $first->updated_at?->toIso8601String();

        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['_token' => 'sensitive-token', 'article_ids' => [$second->id, $first->id]],
        );

        $prepare->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.count', 2)
            ->assertJsonStructure(['data' => ['count', 'filename', 'download_url', 'expires_at']]);
        $this->assertStringStartsWith('/', $prepare->json('data.download_url'));
        $this->assertStringNotContainsString('://', $prepare->json('data.download_url'));

        $downloadUrl = $prepare->json('data.download_url');
        $filename = $prepare->json('data.filename');
        $firstDownload = $this->actingAs($admin, 'admin')->get($downloadUrl);
        $firstDownload->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload($filename);
        $cacheControl = (string) $firstDownload->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->actingAs($admin, 'admin')->get($downloadUrl)->assertOk()->assertDownload($filename);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($firstDownload->baseResponse->getFile()->getPathname()));
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringStartsWith(sprintf('001-%d-', $second->id), $zip->getNameIndex(0));
        $this->assertStringStartsWith(sprintf('002-%d-', $first->id), $zip->getNameIndex(1));
        $zip->close();

        $first->refresh();
        $this->assertSame('第一篇正文', $first->content);
        $this->assertSame($firstUpdatedAt, $first->updated_at?->toIso8601String());

        $this->actingAs($otherAdmin, 'admin')->get($downloadUrl)->assertNotFound();
        $tamperedUrl = str_replace('owner='.$admin->id, 'owner='.$otherAdmin->id, $downloadUrl);
        $this->actingAs($admin, 'admin')->get($tamperedUrl)->assertForbidden();

        $activity = AdminActivityLog::query()->latest('id')->firstOrFail();
        $details = json_decode((string) $activity->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2', $details['article_ids']['count'] ?? null);
        $this->assertSame((string) $second->id, $details['article_ids']['first'] ?? null);
        $this->assertSame((string) $first->id, $details['article_ids']['last'] ?? null);
        $this->assertCount(3, $details['article_ids'] ?? []);
        $this->assertArrayNotHasKey('_token', $details);
    }

    public function test_prepare_validates_selection_and_excludes_deleted_articles(): void
    {
        $admin = $this->admin('export-validation');
        $article = $this->article();
        $deleted = $this->article(['title' => '已删除文章']);
        $deleted->delete();
        $url = route('admin.articles.batch.export-markdown.prepare');

        $this->actingAs($admin, 'admin')->postJson($url, ['article_ids' => []])
            ->assertUnprocessable();
        $this->actingAs($admin, 'admin')->postJson($url, ['article_ids' => [$article->id, $article->id]])
            ->assertUnprocessable();
        $this->actingAs($admin, 'admin')->postJson($url, ['article_ids' => [$deleted->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.article_ids.0', __('admin.articles.export.errors.invalid_selection'));
        $this->actingAs($admin, 'admin')->postJson($url, ['article_ids' => [$deleted->id + 100_000]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.article_ids.0', __('admin.articles.export.errors.invalid_selection'));
        $this->actingAs($admin, 'admin')->postJson($url, ['article_ids' => range(1, 501)])
            ->assertUnprocessable();

        $activity = AdminActivityLog::query()->latest('id')->firstOrFail();
        $details = json_decode((string) $activity->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('501', $details['article_ids']['count'] ?? null);
        $this->assertCount(3, $details['article_ids'] ?? []);
    }

    public function test_prepare_rejects_non_positive_and_non_integer_article_ids(): void
    {
        $admin = $this->admin('export-invalid-identifiers');
        $url = route('admin.articles.batch.export-markdown.prepare');

        foreach ([0, -1, 'article-id'] as $invalidId) {
            $response = $this->actingAs($admin, 'admin')
                ->postJson($url, ['article_ids' => [$invalidId]]);

            $response->assertUnprocessable()->assertJsonValidationErrors('article_ids.0');
            $this->assertSame(
                __('admin.articles.export.errors.invalid_selection'),
                collect($response->json('errors'))->flatten()->first(),
            );
        }
    }

    public function test_prepare_accepts_the_exact_five_hundred_article_boundary(): void
    {
        $admin = $this->admin('export-boundary');
        $articleIds = $this->bulkArticleIds(500, 'export-boundary');

        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => $articleIds],
        );

        $prepare->assertOk()->assertJsonPath('data.count', 500);
        $download = $this->actingAs($admin, 'admin')->get($prepare->json('data.download_url'));
        $download->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($download->baseResponse->getFile()->getPathname()));
        $this->assertSame(500, $zip->numFiles);
        $zip->close();
    }

    public function test_prepare_and_download_supports_fifty_articles(): void
    {
        $admin = $this->admin('export-fifty');
        $articleIds = $this->bulkArticleIds(50, 'export-fifty');

        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => $articleIds],
        );

        $prepare->assertOk()->assertJsonPath('data.count', 50);
        $download = $this->actingAs($admin, 'admin')->get($prepare->json('data.download_url'));
        $download->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($download->baseResponse->getFile()->getPathname()));
        $this->assertSame(50, $zip->numFiles);
        $zip->close();
    }

    public function test_signed_download_path_keeps_the_configured_subdirectory(): void
    {
        config(['app.url' => 'https://configured.example/geoflow']);
        $admin = $this->admin('export-subdirectory');
        $article = $this->article();

        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => [$article->id]],
        );

        $prepare->assertOk();
        $externalPath = (string) $prepare->json('data.download_url');
        $this->assertStringStartsWith(
            '/geoflow/'.trim(AdminWeb::basePath(), '/').'/articles/batch/export-markdown/download/',
            $externalPath,
        );
        $this->assertStringNotContainsString('://', $externalPath);

        $this->withServerVariables([
            'SCRIPT_NAME' => '/geoflow/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'PHP_SELF' => '/geoflow/index.php',
        ])->actingAs($admin, 'admin')->get($externalPath)->assertOk();
    }

    public function test_download_signature_expires_after_ten_minutes(): void
    {
        $this->freezeTime();
        $admin = $this->admin('export-expired');
        $article = $this->article();
        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => [$article->id]],
        );
        $prepare->assertOk();

        $this->travel(11)->minutes();

        $this->actingAs($admin, 'admin')
            ->get($prepare->json('data.download_url'))
            ->assertForbidden();
    }

    public function test_prepare_rejects_concurrent_export_for_the_same_admin(): void
    {
        $admin = $this->admin('export-lock');
        $otherAdmin = $this->admin('export-lock-other');
        $article = $this->article();
        $lock = Cache::lock(
            'geoflow:article-markdown-export:admin:'.$admin->id,
            ArticleMarkdownExportService::BUILD_LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin, 'admin')
                ->postJson(route('admin.articles.batch.export-markdown.prepare'), ['article_ids' => [$article->id]])
                ->assertConflict()
                ->assertJsonPath('message', __('admin.articles.export.errors.in_progress'));

            $this->actingAs($otherAdmin, 'admin')
                ->postJson(route('admin.articles.batch.export-markdown.prepare'), ['article_ids' => [$article->id]])
                ->assertOk();
        } finally {
            $lock->release();
        }
    }

    public function test_prepare_rejects_when_the_global_export_capacity_is_busy(): void
    {
        $admin = $this->admin('export-capacity-lock');
        $article = $this->article();
        $lock = Cache::lock(
            'geoflow:article-markdown-export:capacity',
            ArticleMarkdownExportService::BUILD_LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin, 'admin')
                ->postJson(route('admin.articles.batch.export-markdown.prepare'), ['article_ids' => [$article->id]])
                ->assertConflict()
                ->assertJsonPath('code', 'article_export_capacity_busy');
        } finally {
            $lock->release();
        }
    }

    public function test_prepare_limiter_is_scoped_to_the_admin_on_a_shared_ip(): void
    {
        $firstAdmin = $this->admin('export-rate-first');
        $secondAdmin = $this->admin('export-rate-second');
        $url = route('admin.articles.batch.export-markdown.prepare');

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->actingAs($firstAdmin, 'admin')->postJson($url, ['article_ids' => []])
                ->assertUnprocessable();
        }
        $this->actingAs($firstAdmin, 'admin')->postJson($url, ['article_ids' => []])
            ->assertTooManyRequests();
        $this->actingAs($secondAdmin, 'admin')->postJson($url, ['article_ids' => []])
            ->assertUnprocessable();
    }

    public function test_download_limiter_bounds_signed_link_replay(): void
    {
        $admin = $this->admin('export-download-rate');
        $article = $this->article();
        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => [$article->id]],
        );
        $prepare->assertOk();
        $downloadUrl = (string) $prepare->json('data.download_url');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->actingAs($admin, 'admin')->get($downloadUrl)->assertOk();
        }
        $this->actingAs($admin, 'admin')->get($downloadUrl)->assertTooManyRequests();
    }

    public function test_guest_prepare_request_receives_json_unauthenticated_response(): void
    {
        $this->postJson(route('admin.articles.batch.export-markdown.prepare'), ['article_ids' => [1]])
            ->assertUnauthorized();
    }

    public function test_prepare_rejects_a_very_large_selection_without_expanding_item_rules(): void
    {
        $admin = $this->admin('export-oversized-selection');

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => range(1, 10_000)],
        )->assertUnprocessable()->assertJsonValidationErrors('article_ids');
    }

    public function test_prepare_request_body_limit_runs_before_route_middleware(): void
    {
        $this->withServerVariables([
            'CONTENT_LENGTH' => (string) (ArticleMarkdownExportService::MAX_PREPARE_REQUEST_BYTES + 1),
        ])->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => [1]],
        )->assertStatus(413)->assertJsonPath('code', 'article_export_request_too_large');
    }

    public function test_download_requires_authentication_and_a_valid_existing_artifact(): void
    {
        $admin = $this->admin('export-download-guards');
        $article = $this->article();
        $prepare = $this->actingAs($admin, 'admin')->postJson(
            route('admin.articles.batch.export-markdown.prepare'),
            ['article_ids' => [$article->id]],
        );
        $prepare->assertOk();
        $downloadUrl = (string) $prepare->json('data.download_url');

        auth('admin')->logout();
        $this->get($downloadUrl)->assertRedirect(route('admin.login'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.batch.export-markdown.download', [
                'exportToken' => 'not-a-valid-token',
            ], absolute: false))
            ->assertNotFound();

        File::cleanDirectory($this->exportRoot);
        $this->actingAs($admin, 'admin')->get($downloadUrl)->assertNotFound();
    }

    public function test_prepare_returns_validation_error_when_markdown_content_exceeds_the_byte_budget(): void
    {
        $this->app->instance(ArticleMarkdownExportService::class, new ArticleMarkdownExportService(
            exportRoot: $this->exportRoot,
            maxUncompressedBytes: 1,
        ));
        $admin = $this->admin('export-size-limit');
        $article = $this->article(['content' => '超出预算']);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.batch.export-markdown.prepare'), ['article_ids' => [$article->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('article_ids')
            ->assertJsonPath('errors.article_ids.0', __('admin.articles.export.errors.too_large'));

        $this->assertSame([], File::allFiles($this->exportRoot));
    }

    public function test_prune_command_removes_only_expired_managed_exports(): void
    {
        $adminDirectory = $this->exportRoot.DIRECTORY_SEPARATOR.'42';
        File::makeDirectory($adminDirectory, 0700, true);
        $expiredPath = $adminDirectory.DIRECTORY_SEPARATOR.str_repeat('a', 40).'.zip';
        File::put($expiredPath, 'expired');
        touch($expiredPath, now()->subMinutes(61)->getTimestamp());

        $this->artisan('geoflow:prune-article-exports')
            ->expectsOutput('Pruned article export artifacts: 1')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($expiredPath);
    }

    public function test_prune_command_is_scheduled_hourly_without_overlap(): void
    {
        $event = collect(app(ScheduleManager::class)->events())
            ->first(static fn (mixed $candidate): bool => str_contains(
                (string) ($candidate->command ?? ''),
                'geoflow:prune-article-exports',
            ));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    private function admin(string $key): Admin
    {
        return Admin::query()->create([
            'username' => $key,
            'password' => 'secret-123',
            'email' => $key.'@example.com',
            'display_name' => Str::headline($key),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function article(array $overrides = []): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'markdown-export'],
            ['name' => 'Markdown 导出'],
        );
        $author = Author::query()->firstOrCreate(['name' => 'GEOFlow']);

        return Article::query()->create(array_merge([
            'title' => 'Markdown 导出文章 '.Str::random(6),
            'slug' => 'markdown-export-'.Str::lower(Str::random(10)),
            'excerpt' => '导出摘要',
            'content' => '导出正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'keywords' => 'GEO,Markdown',
            'meta_description' => '导出测试描述',
            'status' => 'draft',
            'review_status' => 'pending',
        ], $overrides));
    }

    /** @return list<int> */
    private function bulkArticleIds(int $count, string $key): array
    {
        $category = Category::query()->create([
            'name' => Str::headline($key),
            'slug' => $key.'-'.Str::lower(Str::random(8)),
        ]);
        $author = Author::query()->create(['name' => Str::headline($key).' Author']);
        $prefix = $key.'-'.Str::lower(Str::random(10));
        $timestamp = now();

        foreach (array_chunk(range(1, $count), 100) as $numbers) {
            DB::table('articles')->insert(array_map(static fn (int $number): array => [
                'title' => '批量导出文章 '.$number,
                'slug' => $prefix.'-'.$number,
                'content' => '正文 '.$number,
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'draft',
                'review_status' => 'pending',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $numbers));
        }

        return Article::query()
            ->where('slug', 'like', $prefix.'-%')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
