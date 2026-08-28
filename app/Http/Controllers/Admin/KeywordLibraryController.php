<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\TitleLibrary;
use App\Support\AdminWeb;
use App\Support\LibraryImportPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 关键词库管理控制器。
 */
class KeywordLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 50;

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.keyword-libraries.index', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 关键词库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $keywords = $this->loadDetailKeywords($libraryId, $search);
        $usageTotal = $this->loadUsageTotal($libraryId);

        return view('admin.keyword-libraries.detail', [
            'pageTitle' => (string) $library->name.__('admin.keyword_detail.page_title_suffix'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'search' => $search,
            'keywords' => $keywords,
            'usageTotal' => $usageTotal,
        ]);
    }

    /**
     * 新增关键词页。
     */
    public function createKeyword(int $libraryId): View
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.keyword-libraries.add-keyword', [
            'pageTitle' => __('admin.keyword_detail.modal_add'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
        ]);
    }

    /**
     * 批量导入关键词页。
     */
    public function createImport(int $libraryId): View
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.keyword-libraries.import', [
            'pageTitle' => __('admin.keyword_libraries.modal_import'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'importLimits' => LibraryImportPolicy::viewLimits(),
        ]);
    }

    /**
     * 在详情页中新增关键词。
     */
    public function storeKeyword(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keyword' => [
                'required',
                'string',
                'max:'.LibraryImportPolicy::KEYWORD_MAX_CHARACTERS,
                LibraryImportPolicy::rejectNullByteRule(__('admin.keyword_detail.error.keyword_invalid')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.keyword_utf8')),
            ],
        ], [
            'keyword.required' => __('admin.keyword_detail.error.keyword_required'),
            'keyword.string' => __('admin.library_validation.keyword_string'),
            'keyword.max' => __('admin.library_validation.keyword_too_long', [
                'max' => LibraryImportPolicy::KEYWORD_MAX_CHARACTERS,
            ]),
        ]);

        $keyword = trim((string) $payload['keyword']);
        if ($keyword === '') {
            return back()->withInput($request->only(['keyword']))->withErrors([
                'keyword' => __('admin.keyword_detail.error.keyword_required'),
            ]);
        }

        $wasCreated = DB::transaction(function () use ($libraryId, $keyword): bool {
            KeywordLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $createdKeyword = Keyword::query()->createOrFirst([
                'library_id' => $libraryId,
                'keyword' => $keyword,
            ], [
                'used_count' => 0,
                'usage_count' => 0,
            ]);
            if (! $createdKeyword->wasRecentlyCreated) {
                return false;
            }

            KeywordLibrary::query()->whereKey($libraryId)->increment('keyword_count');

            return true;
        }, 3);
        if (! $wasCreated) {
            return back()->withInput($request->only(['keyword']))->withErrors([
                'keyword' => __('admin.keyword_detail.error.keyword_exists'),
            ]);
        }

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.add_success'));
    }

    /**
     * 在详情页中删除关键词（支持单条/批量）。
     */
    public function destroyKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('keyword_ids', []);
        $keywordIds = collect($rawIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        if ($keywordIds->isEmpty()) {
            return back()->withErrors(__('admin.keyword_detail.error.select_required'));
        }

        $deletedCount = DB::transaction(function () use ($libraryId, $keywordIds): int {
            KeywordLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $deleted = Keyword::query()
                ->where('library_id', $libraryId)
                ->whereIn('id', $keywordIds->all())
                ->delete();
            if ($deleted > 0) {
                KeywordLibrary::query()->whereKey($libraryId)->decrement('keyword_count', $deleted);
            }

            return $deleted;
        }, 3);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with(
            'message',
            __('admin.keyword_detail.message.delete_success', ['count' => $deletedCount])
        );
    }

    /**
     * 在详情页中更新关键词库基础信息。
     */
    public function updateFromDetail(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $validation = $this->validateLibraryRequest(
            $request,
            __('admin.keyword_detail.error.library_name_required'),
        );
        if ($validation instanceof RedirectResponse) {
            return $validation;
        }
        $payload = $validation;

        $library->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.update_success'));
    }

    /**
     * 在详情页中导入关键词（逐行 + 逗号分隔）。
     */
    public function importKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keywords_text' => [
                ...LibraryImportPolicy::rawTextRules(
                    __('admin.keyword_libraries.error.import_too_large', LibraryImportPolicy::viewLimits()),
                ),
                LibraryImportPolicy::rejectNullByteRule(__('admin.keyword_libraries.error.import_keyword_invalid')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.keyword_import_utf8')),
            ],
        ], [
            'keywords_text.required' => __('admin.keyword_libraries.error.keywords_required'),
            'keywords_text.string' => __('admin.library_validation.import_string'),
        ]);

        $parsedImport = $this->parseKeywordImportText((string) $payload['keywords_text']);
        $keywords = $parsedImport['entries'];
        if ($parsedImport['overflow'] || $keywords->count() > LibraryImportPolicy::MAX_ENTRIES) {
            return back()->withInput([])->withErrors([
                'keywords_text' => __('admin.keyword_libraries.error.import_too_many', [
                    'max' => number_format(LibraryImportPolicy::MAX_ENTRIES),
                ]),
            ]);
        }
        if ($keywords->isEmpty()) {
            return back()->withInput([])->withErrors([
                'keywords_text' => __('admin.keyword_libraries.error.keywords_required'),
            ]);
        }
        if ($keywords->contains(static fn (string $keyword): bool => mb_strlen($keyword, 'UTF-8') > LibraryImportPolicy::KEYWORD_MAX_CHARACTERS)) {
            return back()->withInput([])->withErrors([
                'keywords_text' => __('admin.keyword_libraries.error.import_keyword_too_long', [
                    'max' => LibraryImportPolicy::KEYWORD_MAX_CHARACTERS,
                ]),
            ]);
        }

        $submittedEntryCount = $keywords->count();
        $keywords = $keywords->uniqueStrict()->values();

        $importedCount = DB::transaction(function () use ($keywords, $libraryId): int {
            KeywordLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $rows = $keywords->map(static fn (string $keyword): array => [
                'library_id' => $libraryId,
                'keyword' => $keyword,
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ])->all();

            $attemptImportedCount = 0;
            foreach (array_chunk($rows, LibraryImportPolicy::INSERT_CHUNK_SIZE) as $chunk) {
                $attemptImportedCount += DB::table((new Keyword)->getTable())->insertOrIgnore($chunk);
            }

            if ($attemptImportedCount > 0) {
                KeywordLibrary::query()->whereKey($libraryId)->increment('keyword_count', $attemptImportedCount);
            }

            return $attemptImportedCount;
        }, 3);

        $duplicateCount = $submittedEntryCount - $importedCount;

        $message = __('admin.keyword_libraries.message.import_success', ['count' => $importedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.keyword_libraries.message.import_skip', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 创建关键词库。
     */
    public function store(Request $request): RedirectResponse
    {
        $validation = $this->validateLibraryRequest(
            $request,
            __('admin.keyword_libraries.error.name_required'),
        );
        if ($validation instanceof RedirectResponse) {
            return $validation;
        }
        $payload = $validation;

        KeywordLibrary::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'keyword_count' => 0,
        ]);

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'libraryId' => (int) $library->id,
            'context' => $this->formContext($request),
            'libraryForm' => [
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
            ],
        ]);
    }

    /**
     * 更新关键词库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $validation = $this->validateLibraryRequest(
            $request,
            __('admin.keyword_libraries.error.name_required'),
            true,
        );
        if ($validation instanceof RedirectResponse) {
            return $validation;
        }
        $payload = $validation;

        $library->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        $redirectRoute = ($payload['context'] ?? 'index') === 'detail'
            ? route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            : route('admin.keyword-libraries.index');

        return redirect($redirectRoute)->with('message', __('admin.keyword_libraries.message.update_success'));
    }

    /**
     * 删除关键词库（包含词条）。
     */
    public function destroy(int $libraryId): RedirectResponse
    {
        $blockingTitleLibraries = DB::transaction(function () use ($libraryId): int {
            $library = KeywordLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $referenceCount = TitleLibrary::query()->where('keyword_library_id', $libraryId)->count();
            if ($referenceCount > 0) {
                return $referenceCount;
            }

            Keyword::query()->where('library_id', $libraryId)->delete();
            $library->delete();

            return 0;
        }, 3);
        if ($blockingTitleLibraries > 0) {
            return back()->withErrors([
                'delete' => __('admin.keyword_libraries.error.delete_blocked', ['count' => $blockingTitleLibraries]),
            ]);
        }

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.delete_success'));
    }

    /**
     * @return array<int, array{id:int,name:string,description:string,actual_count:int,created_at:?string,updated_at:?string}>
     */
    private function loadLibraries(): array
    {
        $query = KeywordLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount('keywords as actual_count')
            ->orderByDesc('created_at');

        return $query->get()->map(static function (KeywordLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_keywords:int,avg_keywords:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = KeywordLibrary::query()->count();
        $totalKeywords = Keyword::query()->count();

        return [
            'total_libraries' => $totalLibraries,
            'total_keywords' => $totalKeywords,
            'avg_keywords' => $totalLibraries > 0 ? round($totalKeywords / $totalLibraries, 1) : 0.0,
        ];
    }

    /**
     * @return array{name:string,description:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
        ];
    }

    /**
     * @return array<string,mixed>|RedirectResponse
     */
    private function validateLibraryRequest(Request $request, string $nameRequiredMessage, bool $includeContext = false): array|RedirectResponse
    {
        $rules = [
            'name' => [
                'bail', 'required', 'string',
                LibraryImportPolicy::rejectNullByteRule(__('admin.library_validation.library_name_nul')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.library_name_utf8')),
                'max:100',
            ],
            'description' => [
                'bail', 'nullable', 'string',
                LibraryImportPolicy::rejectNullByteRule(__('admin.library_validation.library_description_nul')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.library_description_utf8')),
                'max:'.LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS,
            ],
        ];
        if ($includeContext) {
            $rules['context'] = ['nullable', 'string', Rule::in(['index', 'detail'])];
        }
        $validator = Validator::make($request->only(array_keys($rules)), $rules, [
            'name.required' => $nameRequiredMessage,
            'name.string' => __('admin.library_validation.library_name_string'),
            'name.max' => __('admin.library_validation.library_name_too_long', ['max' => 100]),
            'description.string' => __('admin.library_validation.library_description_string'),
            'description.max' => __('admin.library_validation.library_description_too_long', [
                'max' => LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS,
            ]),
        ]);
        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($this->safeLibraryOldInput($request, $includeContext));
        }

        return $validator->validated();
    }

    /** @return array<string,string> */
    private function safeLibraryOldInput(Request $request, bool $includeContext): array
    {
        $oldInput = [];
        $name = LibraryImportPolicy::flashableText($request->input('name'), 100);
        $description = LibraryImportPolicy::flashableText(
            $request->input('description'),
            LibraryImportPolicy::DESCRIPTION_MAX_CHARACTERS,
        );
        if ($name !== null) {
            $oldInput['name'] = $name;
        }
        if ($description !== null) {
            $oldInput['description'] = $description;
        }
        $context = $request->input('context');
        if ($includeContext && is_string($context) && in_array($context, ['index', 'detail'], true)) {
            $oldInput['context'] = $context;
        }

        return $oldInput;
    }

    private function formContext(Request $request): string
    {
        $context = $request->query('context', 'index');

        return is_string($context) && in_array($context, ['index', 'detail'], true)
            ? $context
            : 'index';
    }

    /**
     * @return LengthAwarePaginator<int, Keyword>
     */
    private function loadDetailKeywords(int $libraryId, string $search): LengthAwarePaginator
    {
        $query = Keyword::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where('keyword', 'like', '%'.$search.'%');
        }

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * @return array{entries:Collection<int, string>,overflow:bool}
     */
    private function parseKeywordImportText(string $keywordsText): array
    {
        $split = LibraryImportPolicy::splitBounded($keywordsText, '/(?:\R|,)/u');
        if ($split['overflow']) {
            return ['entries' => collect(), 'overflow' => true];
        }

        $keywords = collect();
        foreach ($split['segments'] as $segment) {
            $keyword = trim($segment);
            if ($keyword === '') {
                continue;
            }

            $keywords->push($keyword);
            if ($keywords->count() > LibraryImportPolicy::MAX_ENTRIES) {
                return ['entries' => $keywords, 'overflow' => true];
            }
        }

        return ['entries' => $keywords->values(), 'overflow' => false];
    }

    /**
     * 按 legacy 页面口径统计关键词总使用次数。
     *
     * 统计规则与 bak/admin/keyword-library-detail.php 一致：
     * 通过文章表 original_keyword 与关键词库中的 keyword 进行匹配计数。
     */
    private function loadUsageTotal(int $libraryId): int
    {
        if (! Schema::hasColumn('articles', 'original_keyword')) {
            return 0;
        }

        return (int) Article::query()
            ->whereIn('original_keyword', function ($query) use ($libraryId): void {
                $query->select('keyword')
                    ->from('keywords')
                    ->where('library_id', $libraryId);
            })
            ->count();
    }
}
