<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\MaterialLibraryService;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\LibraryImportPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TaskDLibraryHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_item_api_rejects_arrays_before_the_service_can_cast_them(): void
    {
        [$headers, $keywordLibrary, $titleLibrary] = $this->materialFixtures();

        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", [
                'keyword' => ['unexpected'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.keyword', '关键词必须是字符串');

        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => ['unexpected'],
                'keyword' => ['unexpected'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.title', '标题必须是字符串')
            ->assertJsonPath('error.details.field_errors.keyword', '关联关键词必须是字符串');

        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", ['keyword' => 123])
            ->assertUnprocessable();
        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", ['title' => true])
            ->assertUnprocessable();

        $this->assertDatabaseCount('keywords', 0);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_material_api_rejects_unstorable_library_and_item_text(): void
    {
        [$headers, $keywordLibrary, $titleLibrary] = $this->materialFixtures();

        $this->withHeaders($headers)
            ->post('/api/v1/materials/keyword-libraries', [
                'name' => "internal\0nul",
                'description' => 'valid',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.field_errors.name', 'name 不能包含 NUL 字符');

        $this->withHeaders($headers)
            ->patch("/api/v1/materials/title-libraries/{$titleLibrary->id}", [
                'description' => "invalid-\xFF-utf8",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.field_errors.description', 'description 必须是有效的 UTF-8 文本');

        $this->withHeaders($headers)
            ->post("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", [
                'keyword' => "invalid-\xFF-utf8",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.field_errors.keyword', '关键词必须是有效的 UTF-8 文本');

        $this->withHeaders($headers)
            ->post("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => "invalid-\xFF-title",
                'keyword' => "internal\0nul",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.field_errors.title', '标题必须是有效的 UTF-8 文本')
            ->assertJsonPath('error.details.field_errors.keyword', '关联关键词不能包含 NUL 字符');
    }

    public function test_keyword_item_api_keeps_the_count_exact_for_duplicate_and_distinct_writes(): void
    {
        [$headers, $keywordLibrary] = $this->materialFixtures();

        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", ['keyword' => 'same'])
            ->assertCreated();
        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", ['keyword' => 'same'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'material_item_exists');
        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}/items", ['keyword' => 'different'])
            ->assertCreated();

        $this->assertSame(2, Keyword::query()->where('library_id', $keywordLibrary->id)->count());
        $this->assertSame(2, (int) $keywordLibrary->fresh()->keyword_count);
    }

    public function test_image_upload_and_legacy_create_lock_the_parent_before_writing_and_keep_the_real_count(): void
    {
        Storage::fake('public');
        config()->set('geoflow.legacy_image_path_input', true);
        $admin = $this->createAdmin('image-parent-lock');
        $token = $admin->createToken('image-parent-lock', ['materials:write'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $baseLevel = DB::connection()->transactionLevel();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'level' => DB::connection()->transactionLevel(),
            ];
        });
        $assertParentBeforeInsert = function (array $capturedQueries) use ($baseLevel): void {
            $parentIndex = collect($capturedQueries)->search(
                fn (array $query): bool => $query['level'] > $baseLevel
                    && str_contains($query['sql'], 'select')
                    && str_contains($query['sql'], 'image_libraries')
            );
            $insertIndex = collect($capturedQueries)->search(
                fn (array $query): bool => $query['level'] > $baseLevel
                    && str_contains($query['sql'], 'insert into')
                    && str_contains($query['sql'], 'images')
            );
            $pathRegistryIndex = collect($capturedQueries)->search(
                fn (array $query): bool => $query['level'] > $baseLevel
                    && str_contains($query['sql'], 'managed_image_paths')
            );

            $this->assertIsInt($parentIndex);
            $this->assertIsInt($insertIndex);
            $this->assertIsInt($pathRegistryIndex);
            $this->assertLessThan($pathRegistryIndex, $parentIndex);
            $this->assertLessThan($insertIndex, $parentIndex);
        };

        $uploadLibrary = ImageLibrary::query()->create([
            'name' => 'Upload parent lock',
            'description' => '',
            'image_count' => 99,
            'used_task_count' => 0,
        ]);
        $this->withHeaders($headers)
            ->post("/api/v1/materials/image-libraries/{$uploadLibrary->id}/items", [
                'image' => UploadedFile::fake()->image('parent-lock.png', 20, 10),
            ])
            ->assertCreated();
        $assertParentBeforeInsert($queries);
        $this->assertSame(1, (int) $uploadLibrary->fresh()->image_count);
        $this->assertSame(1, DB::table('images')->where('library_id', $uploadLibrary->id)->count());

        $legacyLibrary = ImageLibrary::query()->create([
            'name' => 'Legacy parent lock',
            'description' => '',
            'image_count' => 99,
            'used_task_count' => 0,
        ]);
        $legacyPath = 'storage/uploads/images/2026/08/legacy-parent-lock.png';
        Storage::disk('public')->put(substr($legacyPath, strlen('storage/')), 'image');
        $queries = [];
        $this->withHeaders($headers)
            ->postJson("/api/v1/materials/image-libraries/{$legacyLibrary->id}/items", [
                'file_path' => $legacyPath,
            ])
            ->assertCreated();
        $assertParentBeforeInsert($queries);
        $this->assertSame(1, (int) $legacyLibrary->fresh()->image_count);
        $this->assertSame(1, DB::table('images')->where('library_id', $legacyLibrary->id)->count());
    }

    public function test_material_api_rejects_oversized_descriptions_for_basic_library_creates_and_updates(): void
    {
        [$headers, $keywordLibrary, $titleLibrary] = $this->materialFixtures();
        $imageLibrary = ImageLibrary::query()->create([
            'name' => 'API Images',
            'description' => 'original image description',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        $keywordLibrary->update(['description' => 'original keyword description']);
        $titleLibrary->update(['description' => 'original title description']);
        $oversized = str_repeat('x', LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS + 1);

        foreach (['keyword-libraries', 'title-libraries', 'image-libraries'] as $type) {
            $name = 'Oversized '.$type;
            $this->withHeaders($headers)
                ->postJson("/api/v1/materials/{$type}", [
                    'name' => $name,
                    'description' => $oversized,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath(
                    'error.details.field_errors.description',
                    __('admin.library_validation.field_too_long', [
                        'field' => 'description',
                        'max' => LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS,
                    ]),
                );
        }
        $this->assertDatabaseMissing('keyword_libraries', ['name' => 'Oversized keyword-libraries']);
        $this->assertDatabaseMissing('title_libraries', ['name' => 'Oversized title-libraries']);
        $this->assertDatabaseMissing('image_libraries', ['name' => 'Oversized image-libraries']);

        foreach ([
            ['keyword-libraries', $keywordLibrary, 'original keyword description'],
            ['title-libraries', $titleLibrary, 'original title description'],
            ['image-libraries', $imageLibrary, 'original image description'],
        ] as [$type, $library, $originalDescription]) {
            $this->withHeaders($headers)
                ->patchJson("/api/v1/materials/{$type}/{$library->id}", [
                    'description' => $oversized,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');
            $this->assertSame($originalDescription, $library->fresh()->description);
        }
    }

    public function test_material_parent_rows_are_reselected_inside_item_write_and_library_delete_transactions(): void
    {
        [, $keywordLibrary] = $this->materialFixtures();
        $service = app(MaterialLibraryService::class);
        $baseLevel = DB::connection()->transactionLevel();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'level' => DB::connection()->transactionLevel(),
            ];
        });

        $service->createItem('keyword-libraries', (int) $keywordLibrary->id, ['keyword' => 'locked write']);

        $this->assertTrue(collect($queries)->contains(
            fn (array $query): bool => $query['level'] > $baseLevel
                && str_contains($query['sql'], 'select')
                && str_contains($query['sql'], 'keyword_libraries')
        ));

        foreach ([
            ['keyword-libraries', KeywordLibrary::query()->create(['name' => 'Delete Keywords', 'description' => '', 'keyword_count' => 0]), 'keyword_libraries'],
            ['title-libraries', TitleLibrary::query()->create([
                'name' => 'Delete Titles', 'description' => '', 'title_count' => 0,
                'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
            ]), 'title_libraries'],
            ['image-libraries', ImageLibrary::query()->create([
                'name' => 'Delete Images', 'description' => '', 'image_count' => 0, 'used_task_count' => 0,
            ]), 'image_libraries'],
        ] as [$type, $library, $table]) {
            $queries = [];
            $service->delete($type, (int) $library->id);
            $this->assertTrue(collect($queries)->contains(
                fn (array $query): bool => $query['level'] > $baseLevel
                    && str_contains($query['sql'], 'select')
                    && str_contains($query['sql'], $table)
            ), "{$table} parent was not selected inside the delete transaction.");
        }
    }

    public function test_material_library_delete_returns_conflict_for_references_and_rolls_back_children_on_parent_failure(): void
    {
        [$headers, $keywordLibrary, $titleLibrary] = $this->materialFixtures();
        $keyword = Keyword::query()->create([
            'library_id' => $keywordLibrary->id,
            'keyword' => 'must survive',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        TitleLibrary::query()->create([
            'name' => 'Reference',
            'description' => '',
            'keyword_library_id' => $keywordLibrary->id,
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/materials/keyword-libraries/{$keywordLibrary->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'material_in_use');
        $this->assertModelExists($keywordLibrary);
        $this->assertModelExists($keyword);

        $task = Task::query()->create(['name' => 'Title reference', 'status' => 'paused', 'title_library_id' => $titleLibrary->id]);
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/materials/title-libraries/{$titleLibrary->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'material_in_use');
        $this->assertModelExists($titleLibrary);
        $task->forceDelete();

        $rollbackLibrary = KeywordLibrary::query()->create(['name' => 'Rollback', 'description' => '', 'keyword_count' => 1]);
        $rollbackKeyword = Keyword::query()->create([
            'library_id' => $rollbackLibrary->id,
            'keyword' => 'rollback child',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        DB::statement("CREATE TRIGGER task_d_api_parent_delete_failure BEFORE DELETE ON keyword_libraries BEGIN SELECT RAISE(ABORT, 'forced parent failure'); END");
        try {
            $this->withHeaders($headers)
                ->deleteJson("/api/v1/materials/keyword-libraries/{$rollbackLibrary->id}")
                ->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS task_d_api_parent_delete_failure');
        }

        $this->assertModelExists($rollbackLibrary);
        $this->assertModelExists($rollbackKeyword);
    }

    public function test_url_commit_cleans_database_text_and_controller_never_leaks_the_database_exception(): void
    {
        $repaired = LibraryImportPolicy::sanitizeDatabaseText("invalid-\xFF\0-text");
        $this->assertTrue(mb_check_encoding($repaired, 'UTF-8'));
        $this->assertStringNotContainsString("\0", $repaired);

        $job = $this->urlImportJob([
            'library_name' => "Storage\0Policy",
            'summary' => "Summary\0with-invalid-\xFF-byte",
            'knowledge_markdown' => "# Know\0ledge\ninvalid-\xFF-byte",
            'keywords' => ['safe keyword'],
            'titles' => ['safe title'],
        ]);

        app(UrlImportProcessingService::class)->commit($job);

        $knowledge = DB::table('knowledge_bases')->first();
        $this->assertNotNull($knowledge);
        $this->assertStringNotContainsString("\0", (string) $knowledge->description);
        $this->assertStringNotContainsString("\0", (string) $knowledge->content);
        $this->assertTrue(mb_check_encoding((string) $knowledge->description, 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $knowledge->content, 'UTF-8'));

        $failingJob = $this->urlImportJob([
            'library_name' => 'Failure policy',
            'summary' => 'summary',
            'knowledge_markdown' => '# knowledge',
            'keywords' => ['safe keyword'],
            'titles' => ['safe title'],
        ]);
        $admin = $failingJob->executionAdmin()->firstOrFail();
        $secret = 'task-d-secret-database-message';
        Exceptions::fake();
        DB::statement("CREATE TRIGGER task_d_url_commit_failure BEFORE INSERT ON knowledge_bases BEGIN SELECT RAISE(ABORT, '{$secret}'); END");
        try {
            $response = $this->actingAs($admin, 'admin')
                ->post(route('admin.url-import.commit', ['jobId' => $failingJob->id]));
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS task_d_url_commit_failure');
        }

        $response->assertRedirect()->assertSessionHasErrors();
        $messages = implode(' ', session('errors')->getBag('default')->all());
        $this->assertStringContainsString(__('admin.url_import.error.commit_failed'), $messages);
        $this->assertStringNotContainsString($secret, $messages);
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => str_starts_with(
                $exception->getMessage(),
                "URL import database commit failed for job {$failingJob->id} (SQLSTATE ",
            ) && ! str_contains($exception->getMessage(), $secret)
        );
        Exceptions::assertNotReported(QueryException::class);
        Exceptions::assertReportedCount(1);
    }

    public function test_large_import_payloads_are_never_flushed_into_the_session(): void
    {
        $admin = $this->createAdmin('large-import-session');
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'Keywords', 'description' => '', 'keyword_count' => 0]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Titles', 'description' => '', 'title_count' => 0,
            'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
        ]);
        $large = str_repeat('x', (4 * 1024 * 1024) + 1);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.import', ['libraryId' => $keywordLibrary->id]), ['keywords_text' => $large])
            ->assertSessionHasErrors('keywords_text');
        $this->assertNull(data_get(session()->all(), '_old_input.keywords_text'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.import', ['libraryId' => $titleLibrary->id]), ['titles_text' => $large])
            ->assertSessionHasErrors('titles_text');
        $this->assertNull(data_get(session()->all(), '_old_input.titles_text'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.import', ['libraryId' => $keywordLibrary->id]), [
                'keywords_text' => implode("\n", array_fill(0, 1_001, 'duplicate')),
            ])
            ->assertSessionHasErrors('keywords_text');
        $this->assertNull(data_get(session()->all(), '_old_input.keywords_text'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.import', ['libraryId' => $titleLibrary->id]), [
                'titles_text' => str_repeat('题', 501),
            ])
            ->assertSessionHasErrors('titles_text');
        $this->assertNull(data_get(session()->all(), '_old_input.titles_text'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'https://example.test',
                'outputs' => array_fill(0, 10_000, 'knowledge'),
            ])
            ->assertSessionHasErrors('outputs');
        $this->assertNull(data_get(session()->all(), '_old_input.outputs'));
    }

    public function test_oversized_library_descriptions_are_not_flushed_into_the_session(): void
    {
        $admin = $this->createAdmin('large-description-session');
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'Keywords', 'description' => '', 'keyword_count' => 0]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Titles', 'description' => '', 'title_count' => 0,
            'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
        ]);
        $oversized = str_repeat('x', LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS + 1);

        foreach ([
            ['post', route('admin.keyword-libraries.store')],
            ['put', route('admin.keyword-libraries.update', ['libraryId' => $keywordLibrary->id])],
            ['post', route('admin.title-libraries.store')],
            ['put', route('admin.title-libraries.update', ['libraryId' => $titleLibrary->id])],
        ] as [$method, $url]) {
            $response = $this->actingAs($admin, 'admin')->{$method}($url, [
                'name' => 'Safe old name',
                'description' => $oversized,
                'context' => 'index',
            ]);

            $response->assertSessionHasErrors('description');
            $response->assertSessionHasInput('name', 'Safe old name');
            $this->assertNull(data_get(session()->all(), '_old_input.description'));
        }
    }

    public function test_url_normalization_failures_are_reported_with_a_fixed_user_message(): void
    {
        $admin = $this->createAdmin('url-normalization-failure');
        Exceptions::fake();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'http://127.0.0.1/private',
                'outputs' => ['knowledge'],
            ])
            ->assertSessionHasErrors([
                'url' => __('admin.url_import.error.invalid_url'),
            ]);

        Exceptions::assertReported(\InvalidArgumentException::class);
    }

    public function test_web_library_and_entry_forms_reject_internal_nul_and_invalid_utf8(): void
    {
        $admin = $this->createAdmin('web-storage-policy');
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'Keywords', 'description' => '', 'keyword_count' => 0]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Titles', 'description' => '', 'title_count' => 0,
            'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
        ]);

        $keywordLibraryResponse = $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.store'), [
                'name' => "internal\0nul",
                'description' => 'valid',
            ]);
        $keywordLibraryResponse->assertSessionHasErrors('name');
        $this->assertNull(data_get(session()->all(), '_old_input.name'));
        $this->assertSame('valid', data_get(session()->all(), '_old_input.description'));

        $titleLibraryResponse = $this->actingAs($admin, 'admin')
            ->put(route('admin.title-libraries.update', ['libraryId' => $titleLibrary->id]), [
                'name' => 'valid',
                'description' => "invalid-\xFF-utf8",
            ]);
        $titleLibraryResponse->assertSessionHasErrors('description');
        $this->assertSame('valid', data_get(session()->all(), '_old_input.name'));
        $this->assertNull(data_get(session()->all(), '_old_input.description'));
        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => $keywordLibrary->id]), [
                'keyword' => "invalid-\xFF-utf8",
            ])
            ->assertSessionHasErrors('keyword');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.titles.store', ['libraryId' => $titleLibrary->id]), [
                'title' => "internal\0nul",
                'keyword' => "invalid-\xFF-utf8",
            ])
            ->assertSessionHasErrors(['title', 'keyword']);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.import', ['libraryId' => $keywordLibrary->id]), [
                'keywords_text' => "valid\ninvalid-\xFF-utf8",
            ])
            ->assertSessionHasErrors('keywords_text');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.import', ['libraryId' => $titleLibrary->id]), [
                'titles_text' => "valid title|invalid-\xFF-utf8",
            ])
            ->assertSessionHasErrors('titles_text');
    }

    public function test_boundary_nul_is_trimmed_before_validation_and_saved_as_safe_text(): void
    {
        $admin = $this->createAdmin('boundary-nul-contract');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.store'), [
                'name' => "\0Boundary keyword library\0",
                'description' => "\0Safe description\0",
            ])
            ->assertRedirect(route('admin.keyword-libraries.index'));

        $keywordLibrary = KeywordLibrary::query()->where('name', 'Boundary keyword library')->firstOrFail();
        $this->assertSame('Safe description', $keywordLibrary->description);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => $keywordLibrary->id]), [
                'keyword' => "\0safe boundary keyword\0",
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $keywordLibrary->id]));

        $this->assertDatabaseHas('keywords', [
            'library_id' => $keywordLibrary->id,
            'keyword' => 'safe boundary keyword',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.store'), [
                'name' => "\0Boundary title library\0",
                'description' => "\0Safe title description\0",
            ])
            ->assertRedirect(route('admin.title-libraries.index'));

        $titleLibrary = TitleLibrary::query()->where('name', 'Boundary title library')->firstOrFail();
        $this->assertSame('Safe title description', $titleLibrary->description);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.titles.store', ['libraryId' => $titleLibrary->id]), [
                'title' => "\0safe boundary title\0",
                'keyword' => "\0safe related keyword\0",
            ])
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $titleLibrary->id]));

        $this->assertDatabaseHas('titles', [
            'library_id' => $titleLibrary->id,
            'title' => 'safe boundary title',
            'keyword' => 'safe related keyword',
        ]);
    }

    public function test_task_d_validation_messages_use_the_english_admin_locale(): void
    {
        app()->setLocale('en');
        $admin = $this->createAdmin('english-library-validation');
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'Keywords', 'description' => '', 'keyword_count' => 0]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Titles', 'description' => '', 'title_count' => 0,
            'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
        ]);

        $webResponse = $this->actingAs($admin, 'admin')
            ->withSession(['locale' => 'en'])
            ->post(route('admin.keyword-libraries.store'), [
                'name' => "internal\0nul",
                'description' => str_repeat('x', LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS + 1),
            ]);
        $webResponse->assertSessionHasErrors(['name', 'description']);
        $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', implode(' ', session('errors')->all()));

        [$headers] = $this->materialFixtures();
        $apiResponse = $this->withHeaders($headers)
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => "invalid-\xFF-title",
                'keyword' => "internal\0nul",
            ]);
        $apiResponse->assertUnprocessable();
        $decodedApiResponse = $apiResponse->json();
        $this->assertIsArray($decodedApiResponse);
        $this->assertSame(
            __('admin.library_validation.validation_failed'),
            data_get($decodedApiResponse, 'error.message'),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\p{Han}/u',
            json_encode($decodedApiResponse, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $serviceValidationResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/materials/keyword-libraries', [
                'name' => "internal\0nul",
                'description' => 'safe',
            ]);
        $serviceValidationResponse->assertUnprocessable();
        $decodedServiceValidationResponse = $serviceValidationResponse->json();
        $this->assertIsArray($decodedServiceValidationResponse);
        $this->assertDoesNotMatchRegularExpression(
            '/\p{Han}/u',
            json_encode($decodedServiceValidationResponse, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $normalizedLengthResponse = $this->withHeaders($headers)
            ->postJson("/api/v1/materials/title-libraries/{$titleLibrary->id}/items", [
                'title' => str_repeat('ﬃ', 167),
            ]);
        $normalizedLengthResponse->assertUnprocessable();
        $decodedNormalizedLengthResponse = $normalizedLengthResponse->json();
        $this->assertIsArray($decodedNormalizedLengthResponse);
        $this->assertDoesNotMatchRegularExpression(
            '/\p{Han}/u',
            json_encode($decodedNormalizedLengthResponse, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        foreach ([
            'admin.library_validation.keyword_import_utf8',
            'admin.library_validation.title_import_utf8',
            'admin.keyword_libraries.error.import_too_large',
            'admin.title_detail.error.import_too_large',
        ] as $key) {
            $message = __($key, LibraryImportPolicy::viewLimits());
            $this->assertNotSame($key, $message);
            $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', $message);
        }

        $this->assertSame('en', config('app.fallback_locale'));
        foreach (['en', 'zh_CN', 'pt_BR', 'ja', 'ru', 'es'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame(
                'admin.library_validation.keyword_utf8',
                __('admin.library_validation.keyword_utf8'),
                "The {$locale} locale must resolve through its catalog or the configured English fallback.",
            );
        }
    }

    public function test_fingerprint_backfill_commits_small_batches_and_can_resume_after_a_failure(): void
    {
        $library = TitleLibrary::query()->create([
            'name' => 'Backfill Titles', 'description' => '', 'title_count' => 0,
            'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
        ]);
        $rows = collect(range(1, 401))->map(static fn (int $index): array => [
            'library_id' => (int) $library->id,
            'title' => 'Historical title '.$index,
            'title_fingerprint' => null,
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
            'created_at' => now(),
        ])->all();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('titles')->insert($chunk);
        }
        $failureId = (int) DB::table('titles')->where('library_id', $library->id)->orderBy('id')->skip(249)->value('id');
        DB::statement("CREATE TRIGGER task_d_backfill_failure BEFORE UPDATE ON titles WHEN OLD.id = {$failureId} AND NEW.title_fingerprint IS NOT NULL BEGIN SELECT RAISE(ABORT, 'forced backfill failure'); END");
        $migration = require database_path('migrations/2026_08_28_000100_backfill_title_fingerprints.php');
        $updateStatements = 0;
        DB::listen(function (QueryExecuted $query) use (&$updateStatements): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update') && str_contains(strtolower($query->sql), 'titles')) {
                $updateStatements++;
            }
        });
        try {
            $migration->up();
            $this->fail('The injected second-batch failure should stop this migration run.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced backfill failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS task_d_backfill_failure');
        }

        $this->assertSame(200, DB::table('titles')->where('library_id', $library->id)->whereNotNull('title_fingerprint')->count());

        $migration->up();
        $migration->up();
        $this->assertSame(401, DB::table('titles')->where('library_id', $library->id)->whereNotNull('title_fingerprint')->count());
        $this->assertSame(401, DB::table('titles')->where('library_id', $library->id)->distinct()->count('title_fingerprint'));
        $this->assertLessThanOrEqual(6, $updateStatements, 'The backfill must use a constant number of writes per 200-row batch.');
    }

    public function test_fingerprint_backfill_fails_after_three_consecutive_unique_conflicts(): void
    {
        $library = TitleLibrary::query()->create([
            'name' => 'Persistent fingerprint conflict',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $titleId = (int) DB::table('titles')->insertGetId([
            'library_id' => $library->id,
            'title' => 'Persistent conflict',
            'title_fingerprint' => null,
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
            'created_at' => now(),
        ]);
        DB::statement(
            "CREATE TRIGGER task_d_persistent_unique_conflict BEFORE UPDATE ON titles WHEN OLD.id = {$titleId} BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: titles.library_id, titles.title_fingerprint'); END"
        );
        $migration = require database_path('migrations/2026_08_28_000100_backfill_title_fingerprints.php');
        $caught = null;
        try {
            $migration->up();
        } catch (QueryException $exception) {
            $caught = $exception;
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS task_d_persistent_unique_conflict');
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertNull(DB::table('titles')->where('id', $titleId)->value('title_fingerprint'));
    }

    /**
     * @return array{array<string,string>,KeywordLibrary,TitleLibrary}
     */
    private function materialFixtures(): array
    {
        $admin = $this->createAdmin('material-hardening-'.uniqid());
        $token = $admin->createToken('task-d-hardening', ['materials:read', 'materials:write'])->plainTextToken;
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'API Keywords', 'description' => '', 'keyword_count' => 0]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'API Titles', 'description' => '', 'title_count' => 0,
            'generation_type' => 'manual', 'generation_rounds' => 1, 'is_ai_generated' => 0,
        ]);

        return [['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $keywordLibrary, $titleLibrary];
    }

    private function createAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => 'Task D Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /** @param array<string,mixed> $analysis */
    private function urlImportJob(array $analysis): UrlImportJob
    {
        $result = [
            'page' => ['title' => 'Task D URL import', 'text' => 'fallback content'],
            'analysis' => $analysis,
            'import' => ['status' => 'preview', 'summary' => null],
        ];

        $suffix = UrlImportJob::query()->count() + 1;
        $admin = $this->createAdmin('task-d-url-import-'.$suffix);
        $model = AiModel::query()->create([
            'name' => 'Task D URL import model '.$suffix,
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('task-d-url-import-key'),
            'model_id' => 'task-d-url-import-model-'.$suffix,
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 10,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return UrlImportJob::query()->create([
            'url' => 'https://example.test/task-d',
            'normalized_url' => 'https://example.test/task-d',
            'source_domain' => 'example.test',
            'page_title' => 'Task D URL import',
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
            'result_json' => json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            'created_by' => 'task-d-test',
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => 1,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => 1,
            'resolved_ai_model_id' => $model->id,
            'resolved_model_source' => 'personal',
            'model_resolved_at' => now(),
        ]);
    }
}
