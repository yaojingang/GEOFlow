<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\TitleGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateTitlesWithAiRequest;
use App\Models\AiModel;
use App\Models\KeywordLibrary;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\TitleGenerationCoordinator;
use App\Support\AdminWeb;
use App\Support\LibraryImportPolicy;
use App\Support\TitleGenerationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * 标题库管理控制器。
 */
class TitleLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 20;

    public function __construct(
        private TitleGenerationCoordinator $titleGenerationCoordinator,
        private AdminAiModelAccessResolver $adminAiModelAccessResolver,
    ) {}

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.title-libraries.index', [
            'pageTitle' => __('admin.title_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 标题库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $titles = $this->loadDetailTitles($libraryId, '');
        $usageTotal = (int) (Title::query()->where('library_id', $libraryId)->sum('used_count') ?? 0);
        $generationRun = TitleGenerationRun::query()
            ->where('title_library_id', $libraryId)
            ->where($this->generationRunActorScope($request))
            ->whereIn('status', [TitleGenerationRun::STATUS_QUEUED, TitleGenerationRun::STATUS_RUNNING])
            ->latest('id')
            ->first();
        $generationRun ??= TitleGenerationRun::query()
            ->where('title_library_id', $libraryId)
            ->where($this->generationRunActorScope($request))
            ->latest('id')
            ->first();
        $generationStatus = $generationRun ? TitleGenerationStatus::payload($generationRun) : null;

        return view('admin.title-libraries.detail', [
            'pageTitle' => (string) $library->name.__('admin.title_detail.page_title_suffix'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'titles' => $titles,
            'usageTotal' => $usageTotal,
            'generationRun' => $generationRun,
            'generationStatus' => $generationStatus,
        ]);
    }

    /**
     * 新增标题页。
     */
    public function createTitle(int $libraryId): View
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.title-libraries.add-title', [
            'pageTitle' => __('admin.title_detail.modal_add'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
        ]);
    }

    /**
     * 批量导入标题页。
     */
    public function createImport(int $libraryId): View
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.title-libraries.import', [
            'pageTitle' => __('admin.title_detail.modal_import'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'importLimits' => LibraryImportPolicy::viewLimits(),
        ]);
    }

    /**
     * AI 生成标题页。
     */
    public function aiGenerate(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $keywordLibraries = KeywordLibrary::query()
            ->select(['id', 'name'])
            ->withCount(['keywords as keyword_count'])
            ->orderByDesc('created_at')
            ->get();
        $aiModels = $this->adminAiModelAccessResolver
            ->usableQuery($request->user('admin'))
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'")
            ->get(['id', 'name']);

        return view('admin.title-libraries.ai-generate', [
            'pageTitle' => __('admin.title_ai_generate.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'keywordLibraries' => $keywordLibraries,
            'aiModels' => $aiModels,
            'maxTitleCount' => (int) config('geoflow.title_ai_max_count', 100_000),
        ]);
    }

    /**
     * 创建后台 AI 标题生成任务。
     */
    public function generateWithAi(GenerateTitlesWithAiRequest $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $validated = $request->validated();
        $payload = [
            'keyword_library_id' => (int) $validated['keyword_library_id'],
            'ai_model_id' => (int) $validated['ai_model_id'],
            'title_count' => (int) $validated['title_count'],
            'title_style' => (string) $validated['title_style'],
            'custom_prompt' => trim((string) ($validated['custom_prompt'] ?? '')),
            'confirmed_keyword_reuse' => (bool) ((int) ($validated['confirmed_keyword_reuse'] ?? 0)),
        ];

        try {
            $model = AiModel::query()->findOrFail($payload['ai_model_id']);
            $this->adminAiModelAccessResolver->assertUsable($request->user('admin'), $model);
            $this->titleGenerationCoordinator->start(
                $library,
                $payload,
                (int) $request->user('admin')?->getAuthIdentifier(),
                app()->getLocale(),
            );
        } catch (AiModelAccessException $exception) {
            return back()
                ->withInput($request->only([
                    'keyword_library_id', 'ai_model_id', 'title_count', 'title_style',
                    'custom_prompt', 'confirmed_keyword_reuse',
                ]))
                ->withErrors(['ai_model_id' => $exception->getErrorCode()]);
        } catch (TitleGenerationException $exception) {
            return back()
                ->withInput($request->only([
                    'keyword_library_id', 'ai_model_id', 'title_count', 'title_style',
                    'custom_prompt', 'confirmed_keyword_reuse',
                ]))
                ->withErrors($this->generationErrorMessage($exception->reason));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput($request->only([
                'keyword_library_id', 'ai_model_id', 'title_count', 'title_style',
                'custom_prompt', 'confirmed_keyword_reuse',
            ]))->withErrors(__('admin.title_ai_generate.error.queue_failed'));
        }

        return redirect()
            ->route('admin.title-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', __('admin.title_ai_generate.message.queued'));
    }

    public function generationStatus(Request $request, int $libraryId, int $runId): JsonResponse
    {
        $run = TitleGenerationRun::query()
            ->where('title_library_id', $libraryId)
            ->where($this->generationRunActorScope($request))
            ->whereKey($runId)
            ->firstOrFail();

        return response()->json(TitleGenerationStatus::payload($run));
    }

    public function retryGeneration(Request $request, int $libraryId, int $runId): RedirectResponse
    {
        $run = TitleGenerationRun::query()
            ->where('title_library_id', $libraryId)
            ->where($this->generationRunActorScope($request))
            ->whereKey($runId)
            ->firstOrFail();

        try {
            $this->titleGenerationCoordinator->retry($run, $request->user('admin'));
        } catch (AiModelAccessException $exception) {
            return back()->withErrors(['ai_model_id' => $exception->getErrorCode()]);
        } catch (TitleGenerationException $exception) {
            return back()->withErrors($this->generationErrorMessage($exception->reason));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(__('admin.title_ai_generate.error.queue_failed'));
        }

        return back()->with('message', __('admin.title_ai_generate.message.retry_queued'));
    }

    public function cancelGeneration(Request $request, int $libraryId, int $runId): RedirectResponse
    {
        $run = TitleGenerationRun::query()
            ->where('title_library_id', $libraryId)
            ->where($this->generationRunActorScope($request))
            ->whereKey($runId)
            ->firstOrFail();

        try {
            $this->titleGenerationCoordinator->cancel($run, $request->user('admin'));
        } catch (TitleGenerationException $exception) {
            return back()->withErrors($this->generationErrorMessage($exception->reason));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(__('admin.title_ai_generate.error.queue_failed'));
        }

        return back()->with('message', __('admin.title_ai_generate.message.cancelled'));
    }

    /**
     * 在详情页中新增标题。
     */
    public function storeTitle(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'title' => [
                'required', 'string', 'max:'.LibraryImportPolicy::TITLE_MAX_CHARACTERS,
                LibraryImportPolicy::rejectNullByteRule(__('admin.library_validation.title_nul')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.title_utf8')),
            ],
            'keyword' => [
                'nullable',
                'string',
                'max:'.LibraryImportPolicy::TITLE_KEYWORD_MAX_CHARACTERS,
                LibraryImportPolicy::rejectNullByteRule(__('admin.title_detail.error.keyword_invalid')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.related_keyword_utf8')),
            ],
        ], [
            'title.required' => __('admin.title_detail.error.title_required'),
            'title.string' => __('admin.library_validation.title_string'),
            'title.max' => __('admin.library_validation.title_too_long', [
                'max' => LibraryImportPolicy::TITLE_MAX_CHARACTERS,
            ]),
            'keyword.string' => __('admin.library_validation.related_keyword_string'),
            'keyword.max' => __('admin.library_validation.related_keyword_too_long', [
                'max' => LibraryImportPolicy::TITLE_KEYWORD_MAX_CHARACTERS,
            ]),
        ]);

        $title = LibraryImportPolicy::normalizeTitle((string) $payload['title']);
        if ($title === '') {
            return back()->withInput($request->only(['title', 'keyword']))->withErrors([
                'title' => __('admin.title_detail.error.title_required'),
            ]);
        }
        if (! LibraryImportPolicy::titleFitsStorage($title)) {
            return back()->withInput($request->only(['title', 'keyword']))->withErrors([
                'title' => __('admin.title_detail.error.import_title_too_long', [
                    'max' => LibraryImportPolicy::TITLE_MAX_CHARACTERS,
                ]),
            ]);
        }

        $inserted = DB::transaction(function () use ($libraryId, $payload, $title): int {
            TitleLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $inserted = DB::table((new Title)->getTable())->insertOrIgnore([
                'library_id' => $libraryId,
                'title' => $title,
                'title_fingerprint' => Title::fingerprintFor($title),
                'keyword' => trim((string) ($payload['keyword'] ?? '')),
                'is_ai_generated' => false,
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ]);
            if ($inserted > 0) {
                TitleLibrary::query()->whereKey($libraryId)->increment('title_count', $inserted);
            }

            return $inserted;
        }, 3);
        if ($inserted === 0) {
            return back()->withInput($request->only(['title', 'keyword']))->withErrors([
                'title' => __('admin.title_detail.error.title_exists'),
            ]);
        }

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.title_detail.message.add_success'));
    }

    /**
     * 删除标题（支持单条/批量）。
     */
    public function destroyTitles(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('title_ids', []);
        $titleIds = collect($rawIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();
        if ($titleIds->isEmpty()) {
            return back()->withErrors(__('admin.title_detail.error.content_required'));
        }

        $deletedCount = DB::transaction(function () use ($libraryId, $titleIds): int {
            TitleLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $deleted = Title::query()
                ->where('library_id', $libraryId)
                ->whereIn('id', $titleIds->all())
                ->delete();
            if ($deleted > 0) {
                TitleLibrary::query()->whereKey($libraryId)->decrement('title_count', $deleted);
            }

            return $deleted;
        }, 3);

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with(
            'message',
            __('admin.title_detail.message.delete_success', ['count' => $deletedCount])
        );
    }

    /**
     * 批量导入标题（支持“标题|关键词”格式）。
     */
    public function importTitles(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'titles_text' => [
                ...LibraryImportPolicy::rawTextRules(
                    __('admin.title_detail.error.import_too_large', LibraryImportPolicy::viewLimits()),
                ),
                LibraryImportPolicy::rejectNullByteRule(__('admin.title_detail.error.import_keyword_invalid')),
                LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.title_import_utf8')),
            ],
        ], [
            'titles_text.required' => __('admin.title_detail.error.content_required'),
            'titles_text.string' => __('admin.library_validation.import_string'),
        ]);

        /** @var Collection<int, array{title:string,keyword:string}> $entries */
        $parsedImport = $this->parseTitleImportText((string) $payload['titles_text']);
        $entries = $parsedImport['entries'];
        if ($parsedImport['overflow'] || $entries->count() > LibraryImportPolicy::MAX_ENTRIES) {
            return back()->withInput([])->withErrors([
                'titles_text' => __('admin.title_detail.error.import_too_many', [
                    'max' => number_format(LibraryImportPolicy::MAX_ENTRIES),
                ]),
            ]);
        }
        if ($entries->isEmpty()) {
            return back()->withInput([])->withErrors([
                'titles_text' => __('admin.title_detail.error.content_required'),
            ]);
        }
        if ($entries->contains(static fn (array $entry): bool => ! LibraryImportPolicy::titleFitsStorage($entry['title']))) {
            return back()->withInput([])->withErrors([
                'titles_text' => __('admin.title_detail.error.import_title_too_long', [
                    'max' => LibraryImportPolicy::TITLE_MAX_CHARACTERS,
                ]),
            ]);
        }
        if ($entries->contains(static fn (array $entry): bool => mb_strlen($entry['keyword'], 'UTF-8') > LibraryImportPolicy::TITLE_KEYWORD_MAX_CHARACTERS)) {
            return back()->withInput([])->withErrors([
                'titles_text' => __('admin.title_detail.error.import_keyword_too_long', [
                    'max' => LibraryImportPolicy::TITLE_KEYWORD_MAX_CHARACTERS,
                ]),
            ]);
        }
        if ($entries->contains(static fn (array $entry): bool => LibraryImportPolicy::containsNullByte($entry['keyword']))) {
            return back()->withInput([])->withErrors([
                'titles_text' => __('admin.title_detail.error.import_keyword_invalid'),
            ]);
        }

        $submittedEntryCount = $entries->count();
        $entries = $entries->uniqueStrict(static fn (array $entry): string => $entry['title'])->values();

        $importedCount = DB::transaction(function () use ($entries, $libraryId): int {
            TitleLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $rows = $entries->map(static fn (array $entry): array => [
                'library_id' => $libraryId,
                'title' => $entry['title'],
                'title_fingerprint' => Title::fingerprintFor($entry['title']),
                'keyword' => $entry['keyword'],
                'is_ai_generated' => false,
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ])->all();

            $attemptImportedCount = 0;
            foreach (array_chunk($rows, LibraryImportPolicy::INSERT_CHUNK_SIZE) as $chunk) {
                $attemptImportedCount += DB::table((new Title)->getTable())->insertOrIgnore($chunk);
            }

            if ($attemptImportedCount > 0) {
                TitleLibrary::query()->whereKey($libraryId)->increment('title_count', $attemptImportedCount);
            }

            return $attemptImportedCount;
        }, 3);

        $duplicateCount = $submittedEntryCount - $importedCount;

        $message = __('admin.title_detail.message.import_success', ['count' => $importedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.title_detail.message.import_skip', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.title-libraries.form', [
            'pageTitle' => __('admin.title_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 创建标题库。
     */
    public function store(Request $request): RedirectResponse
    {
        $validation = $this->validateLibraryRequest(
            $request,
            __('admin.title_libraries.error.name_required'),
        );
        if ($validation instanceof RedirectResponse) {
            return $validation;
        }
        $payload = $validation;

        TitleLibrary::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        return redirect()->route('admin.title-libraries.index')->with('message', __('admin.title_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.title-libraries.form', [
            'pageTitle' => __('admin.title_libraries.page_title'),
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
     * 更新标题库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $validation = $this->validateLibraryRequest(
            $request,
            __('admin.title_libraries.error.name_required'),
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
            ? route('admin.title-libraries.detail', ['libraryId' => $libraryId])
            : route('admin.title-libraries.index');

        return redirect($redirectRoute)->with('message', __('admin.title_libraries.message.update_success'));
    }

    /**
     * 删除标题库（存在任务引用时阻止删除）。
     */
    public function destroy(int $libraryId): RedirectResponse
    {
        $taskBlockHint = DB::transaction(function () use ($libraryId): ?string {
            $library = TitleLibrary::query()->whereKey($libraryId)->lockForUpdate()->firstOrFail();
            $taskCount = Task::withTrashed()->where('title_library_id', $libraryId)->count();
            if ($taskCount > 0) {
                return $this->buildTaskDeleteBlockHint($libraryId, $taskCount);
            }

            Title::query()->where('library_id', $libraryId)->delete();
            $library->delete();

            return null;
        }, 3);
        if ($taskBlockHint !== null) {
            return back()->withErrors(__('admin.title_libraries.error.delete_blocked', ['tasks' => $taskBlockHint]));
        }

        return redirect()->route('admin.title-libraries.index')->with('message', __('admin.title_libraries.message.delete_success'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLibraries(): array
    {
        $query = TitleLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount([
                'titles as actual_count',
                'titles as ai_count' => fn ($builder) => $builder->where('is_ai_generated', true),
            ])
            ->orderByDesc('created_at');

        return $query->get()->map(static function (TitleLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'ai_count' => (int) ($library->ai_count ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_titles:int,ai_titles:int,avg_titles:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = TitleLibrary::query()->count();
        $totalTitles = Title::query()->count();
        $aiTitles = Title::query()->where('is_ai_generated', true)->count();

        return [
            'total_libraries' => $totalLibraries,
            'total_titles' => $totalTitles,
            'ai_titles' => $aiTitles,
            'avg_titles' => $totalLibraries > 0 ? round($totalTitles / $totalLibraries, 1) : 0.0,
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
     * @return LengthAwarePaginator<int, Title>
     */
    private function loadDetailTitles(int $libraryId, string $search): LengthAwarePaginator
    {
        $query = Title::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * @return array{entries:Collection<int, array{title:string,keyword:string}>,overflow:bool}
     */
    private function parseTitleImportText(string $titlesText): array
    {
        $split = LibraryImportPolicy::splitBounded($titlesText, '/\R/u');
        if ($split['overflow']) {
            return ['entries' => collect(), 'overflow' => true];
        }

        $entries = collect();
        foreach ($split['segments'] as $segment) {
            $line = trim($segment);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                [$title, $keyword] = array_pad(explode('|', $line, 2), 2, '');
                $entry = [
                    'title' => LibraryImportPolicy::normalizeTitle((string) $title),
                    'keyword' => trim((string) $keyword),
                ];
            } else {
                $entry = ['title' => LibraryImportPolicy::normalizeTitle($line), 'keyword' => ''];
            }
            if ($entry['title'] === '') {
                continue;
            }

            $entries->push($entry);
            if ($entries->count() > LibraryImportPolicy::MAX_ENTRIES) {
                return ['entries' => $entries, 'overflow' => true];
            }
        }

        return ['entries' => $entries->values(), 'overflow' => false];
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function generateMockTitles(array $keywords, int $count, string $style): array
    {
        $styleTemplates = [
            'professional' => [
                '{keyword}的深度分析与研究',
                '关于{keyword}的专业见解',
                '{keyword}行业发展趋势报告',
            ],
            'attractive' => [
                '你绝对不知道的{keyword}秘密',
                '揭秘{keyword}背后的故事',
                '{keyword}让人意想不到的用途',
            ],
            'seo' => [
                '{keyword}完整指南：从入门到精通',
                '{keyword}常见问题解答大全',
                '如何选择最适合的{keyword}方案',
            ],
            'creative' => [
                '重新定义{keyword}的可能性',
                '如果{keyword}会说话，它会告诉你什么？',
                '当{keyword}遇上创新思维',
            ],
            'question' => [
                '{keyword}真的有用吗？',
                '为什么{keyword}如此重要？',
                '{keyword}的未来在哪里？',
            ],
        ];

        $templates = $styleTemplates[$style] ?? $styleTemplates['professional'];
        $titles = [];
        for ($index = 0; $index < $count; $index++) {
            $keyword = $keywords[array_rand($keywords)];
            $template = $templates[array_rand($templates)];
            $titles[] = str_replace('{keyword}', $keyword, $template);
        }

        return $titles;
    }

    private function generationErrorMessage(string $code): string
    {
        return match ($code) {
            'title_generation_no_keywords' => __('admin.title_ai_generate.error.no_keywords'),
            'title_generation_active' => __('admin.title_ai_generate.error.active_run'),
            'title_generation_not_retryable' => __('admin.title_ai_generate.error.not_retryable'),
            'title_generation_not_cancellable' => __('admin.title_ai_generate.error.not_cancellable'),
            'title_generation_async_queue_required' => __('admin.title_ai_generate.error.async_queue_required'),
            'title_generation_ai_model_unavailable' => __('admin.title_ai_generate.error.ai_model_missing'),
            'title_generation_keyword_reuse_confirmation_required' => __('admin.title_ai_generate.error.keyword_reuse_confirmation_required'),
            'title_generation_capacity_exceeded' => __('admin.title_ai_generate.error.capacity_exceeded'),
            default => __('admin.title_ai_generate.error.queue_failed'),
        };
    }

    private function generationRunActorScope(Request $request): \Closure
    {
        $adminId = (int) $request->user('admin')?->getAuthIdentifier();

        return static function ($query) use ($adminId): void {
            $query->where('model_access_admin_id', $adminId)
                ->orWhere(function ($legacy) use ($adminId): void {
                    $legacy->whereNull('model_access_admin_id')
                        ->where('created_by_admin_id', $adminId);
                });
        };
    }

    /**
     * 生成与 legacy 页面一致的删除阻断提示。
     */
    private function buildTaskDeleteBlockHint(int $libraryId, int $taskCount): string
    {
        $tasks = Task::withTrashed()
            ->where('title_library_id', $libraryId)
            ->select(['id', 'name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $taskPreview = $tasks
            ->map(static fn (Task $task): string => '#'.((int) $task->id).' '.trim((string) ($task->name ?? '')))
            ->filter(static fn (string $name): bool => $name !== '#0')
            ->implode('、');
        if ($taskPreview === '') {
            $taskPreview = __('admin.title_libraries.error.delete_more_tasks', ['count' => $taskCount]);
        }

        if ($taskCount > $tasks->count()) {
            $taskPreview .= __('admin.title_libraries.error.delete_more_tasks', ['count' => $taskCount]);
        }

        return $taskPreview;
    }
}
