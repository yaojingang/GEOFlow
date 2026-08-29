<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminKeywordTitleStandalonePagesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private KeywordLibrary $keywordLibrary;

    private TitleLibrary $titleLibrary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->admin = Admin::query()->create([
            'username' => 'library_standalone_admin',
            'password' => 'secret-123',
            'email' => 'library-standalone@example.com',
            'display_name' => 'Library Standalone Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->keywordLibrary = KeywordLibrary::query()->create([
            'name' => 'GEO 关键词库',
            'description' => '用于独立页面验收',
            'keyword_count' => 0,
        ]);
        $this->titleLibrary = TitleLibrary::query()->create([
            'name' => 'GEO 标题库',
            'description' => '用于独立页面验收',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
    }

    public function test_guests_cannot_open_keyword_or_title_entry_pages(): void
    {
        foreach ($this->entryPageRoutes() as [$routeName, $parameters]) {
            $this->get(route($routeName, $parameters))->assertRedirect(route('admin.login'));
        }
    }

    public function test_authenticated_admin_can_open_all_standalone_entry_pages(): void
    {
        $this->actingAs($this->admin, 'admin');

        foreach ($this->entryPageRoutes() as [$routeName, $parameters]) {
            $this->get(route($routeName, $parameters))
                ->assertOk()
                ->assertSee('active:scale-[0.96]', false);
        }

        $this->get(route('admin.keyword-libraries.keywords.create', ['libraryId' => $this->keywordLibrary->id]))
            ->assertSee(route('admin.keyword-libraries.keywords.store', ['libraryId' => $this->keywordLibrary->id]), false)
            ->assertSee(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]), false);
        $this->get(route('admin.keyword-libraries.import.create', ['libraryId' => $this->keywordLibrary->id]))
            ->assertSee(route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]), false)
            ->assertSee('aria-live="polite"', false);
        $this->get(route('admin.title-libraries.titles.create', ['libraryId' => $this->titleLibrary->id]))
            ->assertSee(route('admin.title-libraries.titles.store', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertSee(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]), false);
        $this->get(route('admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]))
            ->assertSee(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertSee('data-library-entry-form', false);

        foreach ([
            ['admin.keyword-libraries.create', []],
            ['admin.keyword-libraries.edit', ['libraryId' => $this->keywordLibrary->id]],
            ['admin.title-libraries.create', []],
            ['admin.title-libraries.edit', ['libraryId' => $this->titleLibrary->id]],
        ] as [$routeName, $parameters]) {
            $this->get(route($routeName, $parameters))
                ->assertOk()
                ->assertSee('data-library-entry-form', false)
                ->assertSee('data-library-entry-submit', false)
                ->assertSee('data-library-entry-submit-label', false)
                ->assertSee('data-library-entry-status', false);
        }
    }

    public function test_lists_and_details_link_to_standalone_pages_without_ordinary_modals(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.keyword-libraries.index'))
            ->assertOk()
            ->assertSee(route('admin.keyword-libraries.create'), false)
            ->assertSee(route('admin.keyword-libraries.edit', ['libraryId' => $this->keywordLibrary->id]), false)
            ->assertSee(route('admin.keyword-libraries.import.create', ['libraryId' => $this->keywordLibrary->id]), false)
            ->assertDontSee('id="create-modal"', false)
            ->assertDontSee('id="import-modal"', false)
            ->assertDontSee('showCreateModal', false)
            ->assertDontSee('showImportModal', false);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]))
            ->assertOk()
            ->assertSee(route('admin.keyword-libraries.edit', ['libraryId' => $this->keywordLibrary->id, 'context' => 'detail']), false)
            ->assertSee(route('admin.keyword-libraries.keywords.create', ['libraryId' => $this->keywordLibrary->id]), false)
            ->assertSee(route('admin.keyword-libraries.import.create', ['libraryId' => $this->keywordLibrary->id]), false)
            ->assertDontSee('id="add-modal"', false)
            ->assertDontSee('id="edit-modal"', false)
            ->assertDontSee('id="import-modal"', false);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.title-libraries.index'))
            ->assertOk()
            ->assertSee(route('admin.title-libraries.create'), false)
            ->assertSee(route('admin.title-libraries.edit', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertSee(route('admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertDontSee('id="import-modal"', false)
            ->assertDontSee('showImportModal', false);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]))
            ->assertOk()
            ->assertSee(route('admin.title-libraries.edit', ['libraryId' => $this->titleLibrary->id, 'context' => 'detail']), false)
            ->assertSee(route('admin.title-libraries.titles.create', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertSee(route('admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertSee(route('admin.title-libraries.ai-generate', ['libraryId' => $this->titleLibrary->id]), false)
            ->assertDontSee('id="add-modal"', false)
            ->assertDontSee('id="import-modal"', false);
    }

    public function test_edit_context_uses_only_the_fixed_index_or_detail_destinations(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.keyword-libraries.update', ['libraryId' => $this->keywordLibrary->id]), [
                'name' => '详情返回关键词库',
                'description' => '详情上下文',
                'context' => 'detail',
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]));

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.title-libraries.update', ['libraryId' => $this->titleLibrary->id]), [
                'name' => '列表返回标题库',
                'description' => '列表上下文',
                'context' => 'index',
            ])
            ->assertRedirect(route('admin.title-libraries.index'));

        $this->actingAs($this->admin, 'admin')
            ->from(route('admin.title-libraries.edit', ['libraryId' => $this->titleLibrary->id]))
            ->put(route('admin.title-libraries.update', ['libraryId' => $this->titleLibrary->id]), [
                'name' => '非法上下文标题库',
                'description' => '',
                'context' => 'https://example.com/redirect',
            ])
            ->assertRedirect(route('admin.title-libraries.edit', ['libraryId' => $this->titleLibrary->id]))
            ->assertSessionHasErrors('context');
    }

    public function test_existing_add_and_import_post_contracts_still_return_to_the_library_detail(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => $this->keywordLibrary->id]), [
                'keyword' => '生成式搜索优化',
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]));

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]), [
                'keywords_text' => "生成式搜索优化, GEO\n证据链",
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]));

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.title-libraries.titles.store', ['libraryId' => $this->titleLibrary->id]), [
                'title' => '生成式搜索优化实战指南',
                'keyword' => '生成式搜索优化',
            ])
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]));

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), [
                'titles_text' => "生成式搜索优化实战指南|GEO\n证据链如何提升内容可信度|证据链",
            ])
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]));

        $this->assertSame(3, Keyword::query()->where('library_id', $this->keywordLibrary->id)->count());
        $this->assertSame(2, Title::query()->where('library_id', $this->titleLibrary->id)->count());
    }

    public function test_keyword_import_enforces_raw_size_entry_count_and_database_column_length_limits(): void
    {
        $this->actingAs($this->admin, 'admin');
        $importPage = route('admin.keyword-libraries.import.create', ['libraryId' => $this->keywordLibrary->id]);
        $importRoute = route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]);

        $this->get($importPage)
            ->assertOk()
            ->assertSee('4 MB', false)
            ->assertSee('1,000', false)
            ->assertSee('200', false);

        $this->from($importPage)->post($importRoute, [
            'keywords_text' => str_repeat('x', (4 * 1024 * 1024) + 1),
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['keywords_text' => '导入文本不能超过 4 MB。']);

        $tooManyKeywords = collect(range(1, 1001))
            ->map(static fn (int $index): string => 'keyword-'.$index)
            ->implode(',');
        $this->from($importPage)->post($importRoute, [
            'keywords_text' => $tooManyKeywords,
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['keywords_text' => '每次最多导入 1,000 个关键词。']);

        $this->from($importPage)->post($importRoute, [
            'keywords_text' => str_repeat(',', 4 * 1024 * 1024),
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['keywords_text' => '每次最多导入 1,000 个关键词。']);

        $this->from($importPage)->post($importRoute, [
            'keywords_text' => str_repeat('词', 201),
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['keywords_text' => '每个关键词最多 200 个字符。']);

        $maximumLengthKeyword = str_repeat('词', 200);
        $this->post($importRoute, [
            'keywords_text' => $maximumLengthKeyword,
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]));

        $maximumKeywordBatch = collect(range(1, 1000))
            ->map(static fn (int $index): string => 'bounded-keyword-'.$index)
            ->implode(',');
        $maximumKeywordBatch .= "\n".str_repeat(' ', (4 * 1024 * 1024) - strlen($maximumKeywordBatch) - 1);
        $this->post($importRoute, [
            'keywords_text' => $maximumKeywordBatch,
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]));

        $this->assertSame(1001, Keyword::query()->where('library_id', $this->keywordLibrary->id)->count());
        $this->assertSame(1001, $this->keywordLibrary->fresh()->keyword_count);
        $this->assertTrue(Keyword::query()->where('library_id', $this->keywordLibrary->id)->where('keyword', $maximumLengthKeyword)->exists());
    }

    public function test_title_import_enforces_raw_size_entry_count_and_all_database_column_lengths(): void
    {
        $this->actingAs($this->admin, 'admin');
        $importPage = route('admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]);
        $importRoute = route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]);

        $this->get($importPage)
            ->assertOk()
            ->assertSee('4 MB', false)
            ->assertSee('1,000', false)
            ->assertSee('500', false)
            ->assertSee('200', false);

        $this->from($importPage)->post($importRoute, [
            'titles_text' => str_repeat('x', (4 * 1024 * 1024) + 1),
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['titles_text' => '导入文本不能超过 4 MB。']);

        $tooManyTitles = collect(range(1, 1001))
            ->map(static fn (int $index): string => 'title-'.$index)
            ->implode("\n");
        $this->from($importPage)->post($importRoute, [
            'titles_text' => $tooManyTitles,
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['titles_text' => '每次最多导入 1,000 个标题。']);

        $this->from($importPage)->post($importRoute, [
            'titles_text' => 'x'.str_repeat("\n", (4 * 1024 * 1024) - 2).'y',
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['titles_text' => '每次最多导入 1,000 个标题。']);

        $this->from($importPage)->post($importRoute, [
            'titles_text' => str_repeat('题', 501),
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['titles_text' => '每个标题最多 500 个字符。']);

        $normalizationExpansion = str_repeat('ﬃ', 500);
        $this->from($importPage)->post($importRoute, [
            'titles_text' => $normalizationExpansion,
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['titles_text' => '每个标题最多 500 个字符。']);

        $addTitlePage = route('admin.title-libraries.titles.create', ['libraryId' => $this->titleLibrary->id]);
        $this->from($addTitlePage)->post(route('admin.title-libraries.titles.store', ['libraryId' => $this->titleLibrary->id]), [
            'title' => $normalizationExpansion,
        ])->assertRedirect($addTitlePage)
            ->assertSessionHasErrors(['title' => '每个标题最多 500 个字符。']);

        $this->from($importPage)->post($importRoute, [
            'titles_text' => '有效标题|'.str_repeat('词', 201),
        ])->assertRedirect($importPage)
            ->assertSessionHasErrors(['titles_text' => '每个关联关键词最多 200 个字符。']);

        $maximumLengthTitle = str_repeat('题', 500);
        $maximumLengthKeyword = str_repeat('词', 200);
        $this->post($importRoute, [
            'titles_text' => $maximumLengthTitle.'|'.$maximumLengthKeyword,
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]));

        $maximumTitleBatch = collect(range(1, 1000))
            ->map(static fn (int $index): string => 'bounded-title-'.$index)
            ->implode("\n");
        $maximumTitleBatch .= "\n".str_repeat(' ', (4 * 1024 * 1024) - strlen($maximumTitleBatch) - 1);
        $this->post($importRoute, [
            'titles_text' => $maximumTitleBatch,
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]));

        $this->assertSame(1001, Title::query()->where('library_id', $this->titleLibrary->id)->count());
        $this->assertSame(1001, $this->titleLibrary->fresh()->title_count);
        $title = Title::query()->where('library_id', $this->titleLibrary->id)->where('title', $maximumLengthTitle)->sole();
        $this->assertSame($maximumLengthTitle, $title->title);
        $this->assertSame($maximumLengthKeyword, $title->keyword);
    }

    public function test_unique_conflicts_are_reported_as_duplicates_without_server_errors(): void
    {
        $this->actingAs($this->admin, 'admin');
        Keyword::query()->create([
            'library_id' => $this->keywordLibrary->id,
            'keyword' => '并发关键词',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->keywordLibrary->update(['keyword_count' => 1]);

        $addKeywordPage = route('admin.keyword-libraries.keywords.create', ['libraryId' => $this->keywordLibrary->id]);
        $this->from($addKeywordPage)
            ->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => $this->keywordLibrary->id]), [
                'keyword' => '并发关键词',
            ])
            ->assertRedirect($addKeywordPage)
            ->assertSessionHasErrors('keyword');

        $this->post(route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]), [
            'keywords_text' => "并发关键词\n新增关键词",
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]))
            ->assertSessionHas('message', __('admin.keyword_libraries.message.import_success', ['count' => 1]).__('admin.keyword_libraries.message.import_skip', ['count' => 1]));

        Title::query()->create([
            'library_id' => $this->titleLibrary->id,
            'title' => '并发标题',
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->titleLibrary->update(['title_count' => 1]);

        $addTitlePage = route('admin.title-libraries.titles.create', ['libraryId' => $this->titleLibrary->id]);
        $this->from($addTitlePage)
            ->post(route('admin.title-libraries.titles.store', ['libraryId' => $this->titleLibrary->id]), [
                'title' => "  并发\u{200B}标题  ",
            ])
            ->assertRedirect($addTitlePage)
            ->assertSessionHasErrors('title');

        $this->post(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), [
            'titles_text' => "并发标题|已有\n新增标题|新增",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]))
            ->assertSessionHas('message', __('admin.title_detail.message.import_success', ['count' => 1]).__('admin.title_detail.message.import_skip', ['count' => 1]));

        $this->assertSame(2, Keyword::query()->where('library_id', $this->keywordLibrary->id)->count());
        $this->assertSame(2, $this->keywordLibrary->fresh()->keyword_count);
        $this->assertSame(2, Title::query()->where('library_id', $this->titleLibrary->id)->count());
        $this->assertSame(2, $this->titleLibrary->fresh()->title_count);
    }

    public function test_import_duplicate_count_uses_every_valid_submitted_entry(): void
    {
        $this->actingAs($this->admin, 'admin');
        Keyword::query()->create([
            'library_id' => $this->keywordLibrary->id,
            'keyword' => '已有关键词',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->keywordLibrary->update(['keyword_count' => 1]);
        Title::query()->create([
            'library_id' => $this->titleLibrary->id,
            'title' => '已有标题',
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->titleLibrary->update(['title_count' => 1]);

        $this->post(route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]), [
            'keywords_text' => "已有关键词\n已有关键词\n新增关键词\n新增关键词",
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]))
            ->assertSessionHas('message', __('admin.keyword_libraries.message.import_success', ['count' => 1]).__('admin.keyword_libraries.message.import_skip', ['count' => 3]));

        $this->post(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), [
            'titles_text' => "已有标题|已有\n已有标题|重复\n新增标题|新增\n新增标题|重复",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]))
            ->assertSessionHas('message', __('admin.title_detail.message.import_success', ['count' => 1]).__('admin.title_detail.message.import_skip', ['count' => 3]));

        $this->assertSame(2, Keyword::query()->where('library_id', $this->keywordLibrary->id)->count());
        $this->assertSame(2, $this->keywordLibrary->fresh()->keyword_count);
        $this->assertSame(2, Title::query()->where('library_id', $this->titleLibrary->id)->count());
        $this->assertSame(2, $this->titleLibrary->fresh()->title_count);
    }

    public function test_import_deduplication_keeps_distinct_numeric_looking_entries(): void
    {
        $this->actingAs($this->admin, 'admin');

        $this->post(route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]), [
            'keywords_text' => "0\n0e1",
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]));

        $this->post(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), [
            'titles_text' => "0\n0e1",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]));

        $this->assertSame(['0', '0e1'], Keyword::query()
            ->where('library_id', $this->keywordLibrary->id)
            ->orderBy('id')
            ->pluck('keyword')
            ->all());
        $this->assertSame(['0', '0e1'], Title::query()
            ->where('library_id', $this->titleLibrary->id)
            ->orderBy('id')
            ->pluck('title')
            ->all());
        $this->assertSame(2, $this->keywordLibrary->fresh()->keyword_count);
        $this->assertSame(2, $this->titleLibrary->fresh()->title_count);
    }

    public function test_library_deletion_is_blocked_by_references_and_rolls_back_children_when_parent_delete_fails(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->titleLibrary->update(['keyword_library_id' => $this->keywordLibrary->id]);
        $keyword = Keyword::query()->create([
            'library_id' => $this->keywordLibrary->id,
            'keyword' => '删除事务关键词',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $keywordIndex = route('admin.keyword-libraries.index');
        $this->from($keywordIndex)
            ->post(route('admin.keyword-libraries.delete', ['libraryId' => $this->keywordLibrary->id]))
            ->assertRedirect($keywordIndex)
            ->assertSessionHasErrors('delete');
        $this->assertModelExists($this->keywordLibrary);
        $this->assertModelExists($keyword);

        $this->titleLibrary->update(['keyword_library_id' => null]);
        $title = Title::query()->create([
            'library_id' => $this->titleLibrary->id,
            'title' => '删除事务标题',
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $task = Task::query()->create([
            'name' => '已删除任务仍引用标题库',
            'status' => 'paused',
            'title_library_id' => $this->titleLibrary->id,
        ]);
        $task->delete();
        $titleIndex = route('admin.title-libraries.index');
        $this->from($titleIndex)
            ->post(route('admin.title-libraries.delete', ['libraryId' => $this->titleLibrary->id]))
            ->assertRedirect($titleIndex)
            ->assertSessionHasErrors();
        $this->assertModelExists($this->titleLibrary);
        $this->assertModelExists($title);
        $task->forceDelete();

        DB::statement("CREATE TRIGGER task_d_reject_title_library_delete BEFORE DELETE ON title_libraries BEGIN SELECT RAISE(ABORT, 'forced title parent delete failure'); END");
        try {
            $this->post(route('admin.title-libraries.delete', ['libraryId' => $this->titleLibrary->id]))
                ->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS task_d_reject_title_library_delete');
        }
        $this->assertModelExists($this->titleLibrary);
        $this->assertModelExists($title);

        DB::statement("CREATE TRIGGER task_d_reject_keyword_library_delete BEFORE DELETE ON keyword_libraries BEGIN SELECT RAISE(ABORT, 'forced keyword parent delete failure'); END");
        try {
            $this->post(route('admin.keyword-libraries.delete', ['libraryId' => $this->keywordLibrary->id]))
                ->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS task_d_reject_keyword_library_delete');
        }
        $this->assertModelExists($this->keywordLibrary);
        $this->assertModelExists($keyword);
    }

    public function test_keyword_inputs_reject_embedded_null_bytes_for_single_and_batch_entry_flows(): void
    {
        $this->actingAs($this->admin, 'admin');

        $keywordPage = route('admin.keyword-libraries.keywords.create', ['libraryId' => $this->keywordLibrary->id]);
        $this->from($keywordPage)
            ->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => $this->keywordLibrary->id]), [
                'keyword' => "boundary\0null",
            ])
            ->assertRedirect($keywordPage)
            ->assertSessionHasErrors(['keyword' => '关键词不能包含 NUL 字符。']);

        $keywordImportPage = route('admin.keyword-libraries.import.create', ['libraryId' => $this->keywordLibrary->id]);
        $this->from($keywordImportPage)
            ->post(route('admin.keyword-libraries.import', ['libraryId' => $this->keywordLibrary->id]), [
                'keywords_text' => "valid\nboundary\0null",
            ])
            ->assertRedirect($keywordImportPage)
            ->assertSessionHasErrors(['keywords_text' => '关键词不能包含 NUL 字符。']);

        $titlePage = route('admin.title-libraries.titles.create', ['libraryId' => $this->titleLibrary->id]);
        $this->from($titlePage)
            ->post(route('admin.title-libraries.titles.store', ['libraryId' => $this->titleLibrary->id]), [
                'title' => '有效标题',
                'keyword' => "boundary\0null",
            ])
            ->assertRedirect($titlePage)
            ->assertSessionHasErrors(['keyword' => '关联关键词不能包含 NUL 字符。']);

        $titleImportPage = route('admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]);
        $this->from($titleImportPage)
            ->post(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), [
                'titles_text' => "有效标题|boundary\0null",
            ])
            ->assertRedirect($titleImportPage)
            ->assertSessionHasErrors(['titles_text' => '关联关键词不能包含 NUL 字符。']);

        $this->assertDatabaseCount('keywords', 0);
        $this->assertDatabaseCount('titles', 0);
    }

    public function test_every_keyword_and_title_library_id_route_rejects_noncanonical_ids(): void
    {
        $this->actingAs($this->admin, 'admin');
        $keywordBase = parse_url(route('admin.keyword-libraries.index'), PHP_URL_PATH);
        $titleBase = parse_url(route('admin.title-libraries.index'), PHP_URL_PATH);

        foreach (['0', 'abc', '9999999999999999999'] as $invalidId) {
            foreach ([
                ['GET', "{$keywordBase}/{$invalidId}/detail", []],
                ['GET', "{$keywordBase}/{$invalidId}/edit", []],
                ['GET', "{$keywordBase}/{$invalidId}/keywords/create", []],
                ['GET', "{$keywordBase}/{$invalidId}/import", []],
                ['POST', "{$keywordBase}/{$invalidId}/keywords", ['keyword' => 'route-guard']],
                ['POST', "{$keywordBase}/{$invalidId}/keywords/delete", ['keyword_ids' => [1]]],
                ['POST', "{$keywordBase}/{$invalidId}/import", ['keywords_text' => 'route-guard']],
                ['PUT', "{$keywordBase}/{$invalidId}/detail", ['name' => 'route-guard']],
                ['PUT', "{$keywordBase}/{$invalidId}", ['name' => 'route-guard']],
                ['POST', "{$keywordBase}/{$invalidId}/delete", []],
            ] as [$method, $uri, $parameters]) {
                $this->call($method, $uri, $parameters)->assertNotFound();
            }

            foreach ([
                ['GET', "{$titleBase}/{$invalidId}/detail", []],
                ['GET', "{$titleBase}/{$invalidId}/edit", []],
                ['GET', "{$titleBase}/{$invalidId}/titles/create", []],
                ['GET', "{$titleBase}/{$invalidId}/import", []],
                ['GET', "{$titleBase}/{$invalidId}/ai-generate", []],
                ['POST', "{$titleBase}/{$invalidId}/titles", ['title' => 'route-guard']],
                ['POST', "{$titleBase}/{$invalidId}/titles/delete", ['title_ids' => [1]]],
                ['POST', "{$titleBase}/{$invalidId}/import", ['titles_text' => 'route-guard']],
                ['POST', "{$titleBase}/{$invalidId}/ai-generate", []],
                ['POST', "{$titleBase}/{$invalidId}/ai-generation-runs/1/retry", []],
                ['POST', "{$titleBase}/{$invalidId}/ai-generation-runs/1/cancel", []],
                ['GET', "{$titleBase}/{$invalidId}/ai-generation-runs/1/status", []],
                ['PUT', "{$titleBase}/{$invalidId}", ['name' => 'route-guard']],
                ['POST', "{$titleBase}/{$invalidId}/delete", []],
            ] as [$method, $uri, $parameters]) {
                $this->call($method, $uri, $parameters)->assertNotFound();
            }
        }

        foreach (['0', 'abc', '9999999999999999999'] as $invalidRunId) {
            $this->get("{$titleBase}/{$this->titleLibrary->id}/ai-generation-runs/{$invalidRunId}/status")
                ->assertNotFound();
            $this->post("{$titleBase}/{$this->titleLibrary->id}/ai-generation-runs/{$invalidRunId}/retry")
                ->assertNotFound();
            $this->post("{$titleBase}/{$this->titleLibrary->id}/ai-generation-runs/{$invalidRunId}/cancel")
                ->assertNotFound();
        }

        $canonicalId = '123456789012345678';
        $keywordDetailPath = "{$keywordBase}/{$canonicalId}/detail";
        $titleStatusPath = "{$titleBase}/{$canonicalId}/ai-generation-runs/{$canonicalId}/status";

        $this->assertSame(
            'admin.keyword-libraries.detail',
            app('router')->getRoutes()->match(HttpRequest::create($keywordDetailPath, 'GET'))->getName(),
        );
        $this->assertSame(
            'admin.title-libraries.ai-generate.status',
            app('router')->getRoutes()->match(HttpRequest::create($titleStatusPath, 'GET'))->getName(),
        );
        $this->get($keywordDetailPath)->assertNotFound();
        $this->get($titleStatusPath)->assertNotFound();
    }

    public function test_array_old_input_never_breaks_library_or_import_forms(): void
    {
        $keywordCreate = route('admin.keyword-libraries.create');
        $this->actingAs($this->admin, 'admin')
            ->from($keywordCreate)
            ->post(route('admin.keyword-libraries.store'), [
                'name' => ['unexpected'],
                'description' => ['unexpected'],
            ])
            ->assertRedirect($keywordCreate)
            ->assertSessionHasErrors(['name', 'description']);
        $this->get($keywordCreate)
            ->assertOk()
            ->assertDontSee('value="Array"', false)
            ->assertDontSee('>Array</textarea>', false);

        $titleImport = route('admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]);
        $this->actingAs($this->admin, 'admin')
            ->from($titleImport)
            ->post(route('admin.title-libraries.import', ['libraryId' => $this->titleLibrary->id]), [
                'titles_text' => ['unexpected'],
            ])
            ->assertRedirect($titleImport)
            ->assertSessionHasErrors('titles_text');
        $this->get($titleImport)
            ->assertOk()
            ->assertDontSee('>Array</textarea>', false);
    }

    public function test_title_generation_array_input_redirects_and_renders_without_a_server_error(): void
    {
        $aiGeneratePage = route('admin.title-libraries.ai-generate', ['libraryId' => $this->titleLibrary->id]);
        Keyword::query()->create([
            'library_id' => $this->keywordLibrary->id,
            'keyword' => '数组输入验证',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Array Input Model',
            'version' => 'test',
            'api_key' => 'test-key',
            'model_id' => 'array-input-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->from($aiGeneratePage)
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $this->titleLibrary->id]), [
                'keyword_library_id' => $this->keywordLibrary->id,
                'ai_model_id' => $aiModel->id,
                'title_count' => ['10'],
                'title_style' => 'professional',
                'custom_prompt' => ['unexpected'],
            ])
            ->assertRedirect($aiGeneratePage)
            ->assertSessionHasErrors(['title_count', 'custom_prompt']);

        $this->assertSame(0, TitleGenerationRun::query()->count());

        $this->actingAs($this->admin, 'admin')
            ->from($aiGeneratePage)
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $this->titleLibrary->id]), [
                'keyword_library_id' => $this->keywordLibrary->id,
                'ai_model_id' => $aiModel->id,
                'title_count' => 1,
                'title_style' => 'professional',
                'custom_prompt' => ['unexpected'],
            ])
            ->assertRedirect($aiGeneratePage)
            ->assertSessionHasErrors('custom_prompt');

        $this->assertSame(0, TitleGenerationRun::query()->count());

        $this->get($aiGeneratePage)
            ->assertOk()
            ->assertDontSee('value="Array"', false)
            ->assertDontSee('>Array</textarea>', false);

        $this->withSession([
            '_old_input' => [
                'keyword_library_id' => [$this->keywordLibrary->id],
                'ai_model_id' => [$aiModel->id],
                'title_count' => ['10'],
                'title_style' => ['seo'],
                'custom_prompt' => ['unexpected'],
                'confirmed_large_run' => ['1'],
                'confirmed_keyword_reuse' => ['1'],
            ],
        ])->get($aiGeneratePage)
            ->assertOk()
            ->assertDontSee('value="Array"', false)
            ->assertDontSee('>Array</textarea>', false)
            ->assertSee('name="confirmed_keyword_reuse" value="0"', false);
    }

    public function test_title_generation_progress_and_fail_closed_deletion_remain_available(): void
    {
        Keyword::query()->create([
            'library_id' => $this->keywordLibrary->id,
            'keyword' => '保留批量删除确认',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $title = Title::query()->create([
            'library_id' => $this->titleLibrary->id,
            'title' => '保留语义交互',
            'keyword' => '交互',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $run = TitleGenerationRun::query()->create([
            'title_library_id' => $this->titleLibrary->id,
            'created_by_admin_id' => $this->admin->id,
            'status' => TitleGenerationRun::STATUS_QUEUED,
            'requested_count' => 2,
            'model_request_budget' => 6,
            'title_style' => 'professional',
            'keyword_snapshot' => ['交互'],
            'saved_count' => 0,
            'generated_count' => 0,
            'duplicate_count' => 0,
            'batch_count' => 0,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => $this->keywordLibrary->id]))
            ->assertOk()
            ->assertSee('data-library-detail-actions', false)
            ->assertSee('data-keyword-batch-form', false)
            ->assertSee('data-library-detail-destructive-submit', false)
            ->assertSee('disabled aria-disabled="true"', false)
            ->assertDontSee('onclick="toggleBatchActions()"', false)
            ->assertDontSee('function toggleBatchActions()', false);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => $this->titleLibrary->id]))
            ->assertOk()
            ->assertSee('data-title-generation-progress', false)
            ->assertSee('data-load-unavailable=', false)
            ->assertSee(route('admin.title-libraries.ai-generate.status', [
                'libraryId' => $this->titleLibrary->id,
                'runId' => $run->id,
            ]), false)
            ->assertSee('data-generation-notice', false)
            ->assertSee('role="status" aria-live="polite" aria-atomic="true"', false)
            ->assertSee('data-generation-error', false)
            ->assertSee('role="alert" aria-live="assertive" aria-atomic="true"', false)
            ->assertSee('data-library-confirm-form', false)
            ->assertSee('data-library-detail-destructive-submit', false)
            ->assertDontSee('onclick=\'return window.confirm', false)
            ->assertSee('data-material-delete-form', false)
            ->assertSee('value="'.$title->id.'"', false)
            ->assertSee('data-material-delete-submit', false)
            ->assertSee('disabled', false);
    }

    /**
     * @return list<array{string, array<string, int>}>
     */
    private function entryPageRoutes(): array
    {
        return [
            ['admin.keyword-libraries.create', []],
            ['admin.keyword-libraries.edit', ['libraryId' => $this->keywordLibrary->id]],
            ['admin.keyword-libraries.keywords.create', ['libraryId' => $this->keywordLibrary->id]],
            ['admin.keyword-libraries.import.create', ['libraryId' => $this->keywordLibrary->id]],
            ['admin.title-libraries.create', []],
            ['admin.title-libraries.edit', ['libraryId' => $this->titleLibrary->id]],
            ['admin.title-libraries.titles.create', ['libraryId' => $this->titleLibrary->id]],
            ['admin.title-libraries.import.create', ['libraryId' => $this->titleLibrary->id]],
        ];
    }
}
