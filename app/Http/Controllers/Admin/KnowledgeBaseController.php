<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

/**
 * 知识库管理控制器。
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeChunkSyncService $chunkSyncService) {}

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.knowledge-bases.index', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBases' => $this->loadKnowledgeBases(),
            'stats' => $this->loadStats(),
            'hasDefaultEmbeddingModel' => $this->hasDefaultEmbeddingModel(),
        ]);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'knowledgeBaseId' => 0,
            'knowledgeForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 知识库详情页，展示切块与向量状态。
     */
    public function detail(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        return view('admin.knowledge-bases.detail', [
            'pageTitle' => __('admin.knowledge_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'relatedTasks' => $this->loadRelatedTasks($knowledgeBaseId),
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkPreviewRows' => $this->loadChunkPreviewRows($knowledgeBaseId),
        ]);
    }

    /**
     * 详情页更新知识库内容并同步 chunk。
     */
    public function updateFromDetail(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);

        $content = trim((string) $payload['content']);
        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ]);

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            $content,
            'admin.knowledge-bases.detail',
            ['knowledgeBaseId' => $knowledgeBaseId],
            'update_success'
        );
    }

    /**
     * 上传知识文档并写入知识库。
     */
    public function uploadFile(Request $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'upload_success', 'upload_error');
    }

    /**
     * 创建知识库并同步 chunks。
     */
    public function store(Request $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'create_success', 'create_error');
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'knowledgeBaseId' => (int) $knowledgeBase->id,
            'knowledgeForm' => [
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'content' => (string) ($knowledgeBase->content ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'source_name' => (string) ($knowledgeBase->source_name ?? ''),
                'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                'source_type' => (string) ($knowledgeBase->source_type ?? 'document'),
                'business_line' => (string) ($knowledgeBase->business_line ?? ''),
                'effective_date' => $knowledgeBase->effective_date?->toDateString() ?? '',
                'risk_level' => (string) ($knowledgeBase->risk_level ?? 'medium'),
                'review_status' => (string) ($knowledgeBase->review_status ?? 'unreviewed'),
            ],
            'chunkCount' => (int) $knowledgeBase->chunks()->count(),
        ]);
    }

    /**
     * 更新知识库并重建 chunks。
     */
    public function update(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $payload = $this->validateKnowledgeForm($request);
        $content = trim((string) $payload['content']);

        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ] + $this->knowledgeMetadataPayload($payload));

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            $content,
            'admin.knowledge-bases.index',
            [],
            'update_success'
        );
    }

    /**
     * 删除知识库（存在任务引用时阻止）。
     */
    public function destroy(int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $taskCount = $this->knowledgeBaseTaskCount($knowledgeBaseId);
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.knowledge_bases.error.in_use', ['count' => $taskCount]));
        }

        $filePath = (string) ($knowledgeBase->file_path ?? '');
        $knowledgeBase->delete();
        $this->cleanupKnowledgeFile($filePath);

        return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.delete_success'));
    }

    public function refreshChunks(int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();
        $content = trim((string) ($knowledgeBase->content ?? ''));

        if ($content === '') {
            return redirect()
                ->route('admin.knowledge-bases.index')
                ->withErrors(__('admin.knowledge_bases.error.content_required'));
        }

        try {
            $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content, true);
            $stats = $this->loadChunkStats((int) $knowledgeBase->id);
            $vectorizedCount = (int) ($stats['vectorized_count'] ?? 0);

            if ($chunkCount > 0 && $vectorizedCount < $chunkCount) {
                return redirect()
                    ->route('admin.knowledge-bases.index')
                    ->withErrors(__('admin.knowledge_bases.error.embedding_sync_partial', [
                        'chunks' => $chunkCount,
                        'vectorized' => $vectorizedCount,
                    ]));
            }

            return redirect()
                ->route('admin.knowledge-bases.index')
                ->with('message', __('admin.knowledge_bases.message.chunks_refreshed', [
                    'chunks' => $chunkCount,
                    'vectorized' => $vectorizedCount,
                ]));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.knowledge-bases.index')
                ->withErrors(__('admin.knowledge_bases.message.chunks_refresh_error', [
                    'message' => $exception->getMessage(),
                ]));
        }
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadKnowledgeBases(): array
    {
        $query = KnowledgeBase::query()
            ->select(['id', 'name', 'description', 'file_type', 'word_count', 'usage_count', 'created_at', 'updated_at'])
            ->withCount('chunks as chunk_count')
            ->withCount([
                'chunks as vectorized_chunk_count' => fn ($query) => $query
                    ->whereNotNull('embedding_model_id')
                    ->where('embedding_dimensions', '>', 0),
            ])
            ->orderByDesc('created_at');

        return $query->get()->map(static function (KnowledgeBase $knowledgeBase): array {
            return [
                'id' => (int) $knowledgeBase->id,
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'word_count' => (int) ($knowledgeBase->word_count ?? 0),
                'usage_count' => (int) ($knowledgeBase->usage_count ?? 0),
                'chunk_count' => (int) ($knowledgeBase->chunk_count ?? 0),
                'vectorized_chunk_count' => (int) ($knowledgeBase->vectorized_chunk_count ?? 0),
                'created_at' => $knowledgeBase->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * 判断是否存在可用的 embedding 模型，用于知识库列表按钮引导。
     */
    private function hasDefaultEmbeddingModel(): bool
    {
        return AiModel::query()
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
            ->exists();
    }

    /**
     * @return array{total_knowledge:int,total_words:int,markdown_count:int,word_count:int}
     */
    private function loadStats(): array
    {
        return [
            'total_knowledge' => KnowledgeBase::query()->count(),
            'total_words' => (int) (KnowledgeBase::query()->sum('word_count') ?? 0),
            'markdown_count' => KnowledgeBase::query()->where('file_type', 'markdown')->count(),
            'word_count' => KnowledgeBase::query()->where('file_type', 'word')->count(),
        ];
    }

    /**
     * 校验知识库表单。
     *
     * @return array{name:string,description:?string,content:string,file_type:string,source_name:?string,source_url:?string,source_type:?string,business_line:?string,effective_date:?string,risk_level:?string,review_status:?string}
     */
    private function validateKnowledgeForm(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text'],
            'source_name' => ['nullable', 'string', 'max:150'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'source_type' => ['nullable', 'in:document,website,business,faq,other'],
            'business_line' => ['nullable', 'string', 'max:100'],
            'effective_date' => ['nullable', 'date'],
            'risk_level' => ['nullable', 'in:low,medium,high'],
            'review_status' => ['nullable', 'in:unreviewed,reviewed'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);
    }

    /**
     * 校验统一导入表单。
     *
     * @return array{name:?string,description:?string,content:?string,file_type:?string,source_name:?string,source_url:?string,source_type:?string,business_line:?string,effective_date:?string,risk_level:?string,review_status:?string}
     */
    private function validateKnowledgeImportForm(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'file_type' => ['nullable', 'in:markdown,word,text'],
            'source_name' => ['nullable', 'string', 'max:150'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'source_type' => ['nullable', 'in:document,website,business,faq,other'],
            'business_line' => ['nullable', 'string', 'max:100'],
            'effective_date' => ['nullable', 'date'],
            'risk_level' => ['nullable', 'in:low,medium,high'],
            'review_status' => ['nullable', 'in:unreviewed,reviewed'],
            'import_action' => ['nullable', 'in:save,save_and_chunk'],
            'knowledge_file' => ['nullable', File::types(['txt', 'md', 'docx'])->max(50 * 1024)],
            'knowledge_files' => ['nullable', 'array', 'max:10'],
            'knowledge_files.*' => ['file', File::types(['txt', 'md', 'docx'])->max(50 * 1024)],
        ], [
            'knowledge_file.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_file.max' => __('admin.knowledge_bases.error.file_too_large'),
            'knowledge_files.max' => __('admin.knowledge_bases.error.files_limit'),
            'knowledge_files.*.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_files.*.max' => __('admin.knowledge_bases.error.file_too_large'),
        ]);
    }

    /**
     * @return array{name:string,description:string,content:string,file_type:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
            'content' => '',
            'file_type' => 'markdown',
            'source_name' => '',
            'source_url' => '',
            'source_type' => 'document',
            'business_line' => '',
            'effective_date' => '',
            'risk_level' => 'medium',
            'review_status' => 'unreviewed',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{source_name:string,source_url:string,source_type:string,business_line:string,effective_date:?string,risk_level:string,review_status:string}
     */
    private function knowledgeMetadataPayload(array $payload): array
    {
        $sourceType = (string) ($payload['source_type'] ?? 'document');
        $riskLevel = (string) ($payload['risk_level'] ?? 'medium');
        $reviewStatus = (string) ($payload['review_status'] ?? 'unreviewed');

        $metadata = [
            'source_name' => trim((string) ($payload['source_name'] ?? '')),
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
            'source_type' => in_array($sourceType, ['document', 'website', 'business', 'faq', 'other'], true) ? $sourceType : 'document',
            'business_line' => trim((string) ($payload['business_line'] ?? '')),
            'effective_date' => filled($payload['effective_date'] ?? null) ? (string) $payload['effective_date'] : null,
            'risk_level' => in_array($riskLevel, ['low', 'medium', 'high'], true) ? $riskLevel : 'medium',
            'review_status' => in_array($reviewStatus, ['unreviewed', 'reviewed'], true) ? $reviewStatus : 'unreviewed',
        ];

        return array_filter(
            $metadata,
            static fn (mixed $value, string $column): bool => Schema::hasColumn('knowledge_bases', $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function createKnowledgeBaseFromRequest(Request $request, string $successMessageKey, string $errorMessageKey): RedirectResponse
    {
        $payload = $this->validateKnowledgeImportForm($request);
        $storedPaths = [];

        try {
            $manualContent = $this->normalizeKnowledgeText((string) ($payload['content'] ?? ''));
            $uploadedFiles = $this->uploadedKnowledgeFiles($request);

            if (count($uploadedFiles) > 10) {
                throw ValidationException::withMessages([
                    'knowledge_files' => __('admin.knowledge_bases.error.files_limit'),
                ]);
            }

            if ($manualContent === '' && $uploadedFiles === []) {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                    'knowledge_files' => __('admin.knowledge_bases.error.file_required'),
                ]);
            }

            $parsedFiles = $this->parseUploadedKnowledgeFiles($uploadedFiles, $storedPaths);
            $content = $this->mergeKnowledgeSources($manualContent, $parsedFiles);
            if ($content === '') {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                ]);
            }

            $knowledgeName = trim((string) ($payload['name'] ?? ''));
            if ($knowledgeName === '') {
                $knowledgeName = $this->inferKnowledgeName($uploadedFiles);
            }
            if ($knowledgeName === '') {
                $knowledgeName = $this->inferKnowledgeNameFromContent($manualContent);
            }
            if ($knowledgeName === '') {
                throw ValidationException::withMessages([
                    'name' => __('admin.knowledge_bases.error.name_required'),
                ]);
            }

            $fileType = $this->resolveKnowledgeFileType(
                (string) ($payload['file_type'] ?? 'markdown'),
                $manualContent,
                $parsedFiles
            );
            $encodedFilePath = $this->encodeKnowledgeFilePaths($storedPaths);

            $knowledgeBase = DB::transaction(function () use ($knowledgeName, $payload, $content, $fileType, $encodedFilePath): KnowledgeBase {
                return KnowledgeBase::query()->create([
                    'name' => $knowledgeName,
                    'description' => trim((string) ($payload['description'] ?? '')),
                    'content' => $content,
                    'file_type' => $fileType,
                    'character_count' => mb_strlen($content, 'UTF-8'),
                    'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
                    'usage_count' => 0,
                    'used_task_count' => 0,
                    'file_path' => $encodedFilePath,
                ] + $this->knowledgeMetadataPayload($payload));
            });

            if (($payload['import_action'] ?? 'save_and_chunk') === 'save') {
                return redirect()
                    ->route('admin.knowledge-bases.index')
                    ->with('message', __('admin.knowledge_bases.message.create_saved'));
            }

            return $this->redirectAfterChunkSync(
                $knowledgeBase,
                $content,
                'admin.knowledge-bases.index',
                [],
                $successMessageKey
            );
        } catch (ValidationException $exception) {
            $this->cleanupKnowledgeFiles($storedPaths);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->cleanupKnowledgeFiles($storedPaths);

            return back()
                ->withInput($request->except(['knowledge_file', 'knowledge_files']))
                ->withErrors(__('admin.knowledge_bases.message.'.$errorMessageKey, ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 保存知识库后再执行切片同步，避免外部模型调用占用数据库事务。
     *
     * @param  array<string, mixed>  $routeParameters
     */
    private function redirectAfterChunkSync(KnowledgeBase $knowledgeBase, string $content, string $routeName, array $routeParameters, string $successMessageKey): RedirectResponse
    {
        try {
            $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

            return redirect()
                ->route($routeName, $routeParameters)
                ->with('message', __('admin.knowledge_bases.message.'.$successMessageKey, ['count' => $chunkCount]));
        } catch (\Throwable $exception) {
            return redirect()
                ->route($routeName, $routeParameters)
                ->withErrors([
                    'chunk_sync' => __('admin.knowledge_bases.message.chunk_sync_deferred', [
                        'message' => $exception->getMessage(),
                    ]),
                ]);
        }
    }

    /**
     * @return array{chunk_count:int,vectorized_count:int}
     */
    private function loadChunkStats(int $knowledgeBaseId): array
    {
        $chunkCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->count();
        $vectorizedCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_dimensions', '>', 0)
            ->count();

        return ['chunk_count' => $chunkCount, 'vectorized_count' => $vectorizedCount];
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    private function loadRelatedTasks(int $knowledgeBaseId): EloquentCollection
    {
        $taskIds = $this->taskIdsUsingKnowledgeBase($knowledgeBaseId);
        if ($taskIds === []) {
            return Task::query()->whereRaw('1 = 0')->get();
        }

        return Task::query()
            ->select(['id', 'name', 'status', 'updated_at'])
            ->whereIn('id', $taskIds)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    private function knowledgeBaseTaskCount(int $knowledgeBaseId): int
    {
        return count($this->taskIdsUsingKnowledgeBase($knowledgeBaseId));
    }

    /**
     * @return list<int>
     */
    private function taskIdsUsingKnowledgeBase(int $knowledgeBaseId): array
    {
        $taskIds = Task::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (Schema::hasTable('task_knowledge_bases')) {
            $taskIds = array_merge(
                $taskIds,
                DB::table('task_knowledge_bases')
                    ->where('knowledge_base_id', $knowledgeBaseId)
                    ->pluck('task_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all()
            );
        }

        return collect($taskIds)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadChunkPreviewRows(int $knowledgeBaseId): Collection
    {
        return KnowledgeChunk::query()
            ->select([
                'chunk_index',
                'content',
                'chunk_title',
                'section_path',
                'chunk_strategy',
                'token_count',
                'embedding_model_id',
                'embedding_dimensions',
                'embedding_provider',
            ])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->limit(20)
            ->get()
            ->map(static function (KnowledgeChunk $chunk): array {
                $preview = mb_substr(trim((string) $chunk->content), 0, 160, 'UTF-8');

                return [
                    'chunk_index' => (int) $chunk->chunk_index,
                    'content_length' => mb_strlen((string) $chunk->content, 'UTF-8'),
                    'token_count' => (int) ($chunk->token_count ?? 0),
                    'embedding_model_id' => $chunk->embedding_model_id !== null ? (int) $chunk->embedding_model_id : null,
                    'embedding_dimensions' => (int) ($chunk->embedding_dimensions ?? 0),
                    'embedding_provider' => (string) ($chunk->embedding_provider ?? ''),
                    'chunk_title' => (string) ($chunk->chunk_title ?? ''),
                    'section_path' => (string) ($chunk->section_path ?? ''),
                    'chunk_strategy' => (string) ($chunk->chunk_strategy ?? 'structured_rule'),
                    'content_preview' => $preview,
                ];
            });
    }

    /**
     * 保存上传知识文件到本地路径。
     */
    private function storeUploadedKnowledgeFile(UploadedFile $file): string
    {
        $relativeDirectory = 'uploads/knowledge';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'txt');
        $filename = uniqid('', true).'.'.$extension;
        $relativePath = Storage::disk('local')->putFileAs($relativeDirectory, $file, $filename);
        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
        }

        return $relativePath;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function uploadedKnowledgeFiles(Request $request): array
    {
        $files = [];

        /** @var UploadedFile|null $legacyFile */
        $legacyFile = $request->file('knowledge_file');
        if ($legacyFile instanceof UploadedFile) {
            $files[] = $legacyFile;
        }

        $multiFiles = $request->file('knowledge_files', []);
        if (is_array($multiFiles)) {
            foreach ($multiFiles as $file) {
                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<int, string>  $storedPaths
     * @return array<int, array{content:string,file_type:string,original_name:string}>
     */
    private function parseUploadedKnowledgeFiles(array $uploadedFiles, array &$storedPaths): array
    {
        $parsedFiles = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($uploadedFile);
            $storedPaths[] = $storedRelativePath;
            $parsed = $this->parseUploadedKnowledgeFile(
                Storage::disk('local')->path($storedRelativePath),
                $uploadedFile->getClientOriginalName()
            );

            $parsedFiles[] = [
                'content' => $parsed['content'],
                'file_type' => $parsed['file_type'],
                'original_name' => (string) $uploadedFile->getClientOriginalName(),
            ];
        }

        return $parsedFiles;
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    private function mergeKnowledgeSources(string $manualContent, array $parsedFiles): string
    {
        if ($manualContent !== '' && $parsedFiles === []) {
            return $manualContent;
        }

        $blocks = [];
        if ($manualContent !== '') {
            $blocks[] = "# 手动输入内容\n\n".$manualContent;
        }

        foreach ($parsedFiles as $parsedFile) {
            $fileName = trim((string) $parsedFile['original_name']);
            $blocks[] = '# 文件：'.$fileName."\n\n".trim((string) $parsedFile['content']);
        }

        return $this->normalizeKnowledgeText(implode("\n\n---\n\n", $blocks));
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    private function inferKnowledgeName(array $uploadedFiles): string
    {
        if ($uploadedFiles === []) {
            return '';
        }

        $firstName = pathinfo((string) $uploadedFiles[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $firstName = trim($firstName);
        if (count($uploadedFiles) === 1) {
            return $firstName;
        }

        return $firstName === ''
            ? __('admin.knowledge_bases.imported_multi_file_name', ['count' => count($uploadedFiles)])
            : __('admin.knowledge_bases.imported_multi_file_name_with_first', [
                'name' => $firstName,
                'count' => count($uploadedFiles),
            ]);
    }

    private function inferKnowledgeNameFromContent(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        foreach ($lines as $line) {
            $candidate = trim((string) $line);
            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/^#{1,6}\s*/u', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/^[-*+]\s+/u', '', $candidate) ?? $candidate;
            $candidate = trim(strip_tags($candidate));
            $candidate = trim($candidate, " \t\n\r\0\x0B#*_`>");

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 60, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    private function resolveKnowledgeFileType(string $requestedType, string $manualContent, array $parsedFiles): string
    {
        if ($parsedFiles === []) {
            return in_array($requestedType, ['markdown', 'word', 'text'], true) ? $requestedType : 'markdown';
        }

        if ($manualContent !== '' || count($parsedFiles) > 1) {
            return 'markdown';
        }

        $fileType = (string) ($parsedFiles[0]['file_type'] ?? 'markdown');

        return in_array($fileType, ['markdown', 'word', 'text'], true) ? $fileType : 'markdown';
    }

    /**
     * @param  array<int, string>  $storedPaths
     */
    private function encodeKnowledgeFilePaths(array $storedPaths): string
    {
        if ($storedPaths === []) {
            return '';
        }

        if (count($storedPaths) === 1) {
            return (string) $storedPaths[0];
        }

        return (string) json_encode(array_values($storedPaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{content:string,file_type:string}
     */
    private function parseUploadedKnowledgeFile(string $absolutePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'txt' || $extension === 'md') {
            $raw = @file_get_contents($absolutePath);
            if ($raw === false) {
                throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
            }

            $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($raw));
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            return [
                'content' => $content,
                'file_type' => $extension === 'md' ? 'markdown' : 'text',
            ];
        }

        if ($extension === 'docx') {
            $content = $this->extractDocxContent($absolutePath);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
            }

            return [
                'content' => $content,
                'file_type' => 'word',
            ];
        }

        throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
    }

    /**
     * 清理上传失败或删除后的知识文件。
     */
    private function cleanupKnowledgeFile(string $relativePath): void
    {
        $this->cleanupKnowledgeFiles($this->decodeKnowledgeFilePaths($relativePath));
    }

    /**
     * @return array<int, string>
     */
    private function decodeKnowledgeFilePaths(string $storedValue): array
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return [];
        }

        $decoded = json_decode($storedValue, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($path): bool => is_string($path) && trim($path) !== ''));
        }

        return [$storedValue];
    }

    /**
     * @param  array<int, string>  $relativePaths
     */
    private function cleanupKnowledgeFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $this->deleteKnowledgeFilePath($relativePath);
        }
    }

    private function deleteKnowledgeFilePath(string $relativePath): void
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return;
        }

        // 兼容新旧两种路径：优先 Laravel local 磁盘，相对旧数据再回退到项目根目录删除。
        if (Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);

            return;
        }

        $absolutePath = base_path(ltrim($relativePath, '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * 将文本转换为 UTF-8，兼容上传文件编码差异。
     */
    private function convertUploadedTextToUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if (! $detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);

        return $converted === false ? $text : $converted;
    }

    /**
     * 统一知识文本换行与空白，提升分块稳定性。
     */
    private function normalizeKnowledgeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

        return trim((string) $text);
    }

    /**
     * 从 docx 提取正文（优先 ZipArchive，失败时降级为空字符串）。
     */
    private function extractDocxContent(string $absolutePath): string
    {
        if (! class_exists('ZipArchive')) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xmlContent) || $xmlContent === '') {
            return '';
        }

        $dom = new \DOMDocument;
        $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $parts = [];
        $nodes = $xpath->query('//w:t');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = trim((string) $node->textContent);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return $this->normalizeKnowledgeText(implode("\n", $parts));
    }
}
