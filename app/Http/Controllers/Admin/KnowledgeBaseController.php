<?php

namespace App\Http\Controllers\Admin;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseRevision;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Services\AiWorkspace\AdminHelpFeatureRegistry;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactLibraryPresenter;
use App\Services\GeoFlow\KnowledgeSourceParser;
use App\Services\GeoFlow\MaterialLibraryService;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 知识库管理控制器。
 */
class KnowledgeBaseController extends Controller
{
    private const MAX_KNOWLEDGE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly KnowledgeChunkSyncCoordinator $chunkSyncCoordinator,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
        private readonly SystemKnowledgeBaseManager $systemKnowledgeBases,
        private readonly AdminHelpFeatureRegistry $adminHelpFeatures,
        private readonly KnowledgeFactLibraryPresenter $factLibraryPresenter,
        private readonly MaterialLibraryService $materialLibraryService,
        private readonly KnowledgeSourceParser $sourceParser,
    ) {}

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
        $knowledgeBase = KnowledgeBase::query()
            ->with([
                'systemBinding', 'revisions.creator', 'mediaAssets.creator', 'factLibrary.activeRevision',
            ])
            ->whereKey($knowledgeBaseId)
            ->firstOrFail();
        $isSystemKnowledge = $knowledgeBase->isSystemManaged();
        $admin = auth('admin')->user();
        $canEditSystemKnowledge = $admin?->canManageProtectedWorkflows() === true;
        $systemKnowledgeHealth = $isSystemKnowledge ? $this->systemKnowledgeBases->health($knowledgeBase) : null;
        $systemOfficialContent = null;
        if ($canEditSystemKnowledge && ($systemKnowledgeHealth['is_customized'] ?? false)) {
            $definition = $this->systemKnowledgeBases->definition(
                (string) $knowledgeBase->systemBinding?->system_key,
            );
            $systemOfficialContent = $this->systemKnowledgeBases->bundledContent($definition);
        }

        return view('admin.knowledge-bases.detail', [
            'pageTitle' => __('admin.knowledge_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'relatedTasks' => $this->loadRelatedTasks($knowledgeBaseId),
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkPreviewRows' => $this->loadChunkPreviewRows($knowledgeBaseId),
            'factSummary' => $knowledgeBase->factLibrary ? $this->factLibraryPresenter->summary($knowledgeBase->factLibrary) : null,
            'isSystemKnowledge' => $isSystemKnowledge,
            'systemKnowledgeHealth' => $systemKnowledgeHealth,
            'systemOfficialContent' => $systemOfficialContent,
            'canEditSystemKnowledge' => $canEditSystemKnowledge,
            'knowledgeMediaAssets' => $knowledgeBase->mediaAssets
                ->filter(fn ($asset): bool => $admin instanceof Admin
                    && $this->adminHelpFeatures->canAccessRoute($admin, (string) $asset->route_name))
                ->values(),
        ]);
    }

    /**
     * 知识切片管理页。
     */
    public function chunks(int $knowledgeBaseId): View
    {
        $knowledgeBase = KnowledgeBase::query()
            ->with('systemBinding')
            ->whereKey($knowledgeBaseId)
            ->firstOrFail();
        $admin = auth('admin')->user();

        return view('admin.knowledge-bases.chunks.index', [
            'pageTitle' => __('admin.knowledge_chunks.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkRows' => $this->paginateChunkRows($knowledgeBaseId),
            'systemReadOnly' => $knowledgeBase->isSystemManaged()
                && ! ($admin instanceof Admin && $admin->canManageProtectedWorkflows()),
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
            'file_type' => ['required', 'in:markdown,word,text,pdf,presentation'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);

        $content = trim((string) $payload['content']);
        $this->assertKnowledgeContentSize($content);

        if ($knowledgeBase->isSystemManaged()) {
            $admin = $request->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
            $contentChanged = ! hash_equals(
                hash('sha256', trim((string) $knowledgeBase->content)),
                hash('sha256', $content),
            );

            try {
                $this->systemKnowledgeBases->update($knowledgeBase, $admin, $payload);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['content' => $exception->getMessage()]);
            }
            if ($contentChanged) {
                $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识正文已更新');
            }
            $savedContentHash = hash('sha256', (string) $knowledgeBase->fresh()->content);
            AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.updated', [
                'knowledge_base_id' => $knowledgeBaseId,
                'content_hash' => $savedContentHash,
                'content_changed' => $contentChanged,
            ]);

            return redirect()
                ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
                ->with('message', __($contentChanged
                    ? 'admin.knowledge_bases.message.chunk_sync_queued'
                    : 'admin.knowledge_bases.message.saved_without_reindex'));
        }

        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ]);
        $this->qualityInvalidationService->invalidateKnowledgeBase(
            $knowledgeBaseId,
            '知识库正文已更新',
        );

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            'admin.knowledge-bases.detail',
            ['knowledgeBaseId' => $knowledgeBaseId],
        );
    }

    /**
     * 上传知识文档并写入知识库。
     */
    public function uploadFile(Request $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'upload_error');
    }

    /**
     * 创建知识库并同步 chunks。
     */
    public function store(Request $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'create_error');
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->whereKey($knowledgeBaseId)->firstOrFail();
        $isSystemKnowledge = $knowledgeBase->isSystemManaged();

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
            'knowledgeBase' => $knowledgeBase,
            'chunkCount' => (int) $knowledgeBase->chunks()->count(),
            'isSystemKnowledge' => $isSystemKnowledge,
            'systemKnowledgeHealth' => $isSystemKnowledge ? $this->systemKnowledgeBases->health($knowledgeBase) : null,
            'canEditSystemKnowledge' => auth('admin')->user()?->canManageProtectedWorkflows() === true,
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

        if ($knowledgeBase->isSystemManaged()) {
            $admin = $request->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
            $contentChanged = ! hash_equals(
                hash('sha256', trim((string) $knowledgeBase->content)),
                hash('sha256', $content),
            );

            try {
                $this->systemKnowledgeBases->update($knowledgeBase, $admin, $payload);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['content' => $exception->getMessage()]);
            }
            if ($contentChanged) {
                $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识正文已更新');
            }
            AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.updated', [
                'knowledge_base_id' => $knowledgeBaseId,
                'content_hash' => hash('sha256', (string) $knowledgeBase->fresh()->content),
                'content_changed' => $contentChanged,
            ]);

            return redirect()
                ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
                ->with('message', __($contentChanged
                    ? 'admin.knowledge_bases.message.chunk_sync_queued'
                    : 'admin.knowledge_bases.message.saved_without_reindex'));
        }

        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ] + $this->knowledgeMetadataPayload($payload));
        $this->qualityInvalidationService->invalidateKnowledgeBase(
            $knowledgeBaseId,
            '知识库正文或审核元数据已更新',
        );

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            'admin.knowledge-bases.index',
            [],
        );
    }

    /**
     * 删除知识库（存在任务引用时阻止）。
     */
    public function destroy(int $knowledgeBaseId): RedirectResponse
    {
        try {
            $this->materialLibraryService->delete('knowledge-bases', $knowledgeBaseId);
        } catch (ApiException $exception) {
            if ($exception->getErrorCode() === 'material_in_use') {
                $details = $exception->getDetails();
                $count = (int) ($details['task_count'] ?? 0) + (int) ($details['article_count'] ?? 0);

                return back()->withErrors(__('admin.knowledge_bases.error.in_use', ['count' => max(1, $count)]));
            }

            return back()->withErrors($exception->getMessage());
        }

        return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.delete_success'));
    }

    public function refreshChunks(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();
        if ($knowledgeBase->isSystemManaged()) {
            $admin = $request->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
        }
        $content = trim((string) ($knowledgeBase->content ?? ''));
        $redirect = $this->knowledgeChunkRefreshRedirect($request);

        if ($content === '') {
            return $redirect
                ->withErrors(__('admin.knowledge_bases.error.content_required'));
        }

        $this->chunkSyncCoordinator->request(
            (int) $knowledgeBase->id,
            SystemAiIdentity::knowledgeIndex(),
            requireRealEmbedding: ! $knowledgeBase->isSystemManaged(),
            force: true,
        );

        return $redirect->with('message', __('admin.knowledge_bases.message.chunks_refresh_queued'));
    }

    public function restoreRevision(Request $request, int $knowledgeBaseId, int $revisionId): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->findOrFail($knowledgeBaseId);
        $revision = KnowledgeBaseRevision::query()->findOrFail($revisionId);

        try {
            $restored = $this->systemKnowledgeBases->restore($knowledgeBase, $revision, $admin);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['revision' => $exception->getMessage()]);
        }
        $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识修订已恢复');
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.revision_restored', [
            'knowledge_base_id' => $knowledgeBaseId,
            'revision_id' => $restored->getKey(),
            'restored_from_revision_id' => $revisionId,
        ]);

        return redirect()
            ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
            ->with('message', __('admin.knowledge_bases.message.chunk_sync_queued'));
    }

    public function adoptOfficial(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->findOrFail($knowledgeBaseId);
        $officialDefinition = $this->systemKnowledgeBases->definition(
            (string) $knowledgeBase->systemBinding?->system_key,
        );
        $contentChanged = ! hash_equals(
            hash('sha256', (string) $knowledgeBase->content),
            (string) $officialDefinition['content_hash'],
        );
        $revision = $this->systemKnowledgeBases->adoptOfficial($knowledgeBase, $admin);
        if ($contentChanged) {
            $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识已采用当前官方版本');
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.official_adopted', [
            'knowledge_base_id' => $knowledgeBaseId,
            'revision_id' => $revision->getKey(),
            'content_changed' => $contentChanged,
        ]);

        return redirect()
            ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
            ->with('message', __($contentChanged
                ? 'admin.knowledge_bases.message.chunk_sync_queued'
                : 'admin.knowledge_bases.message.saved_without_reindex'));
    }

    private function knowledgeChunkRefreshRedirect(Request $request): RedirectResponse
    {
        $redirectTo = trim((string) $request->input('redirect_to', ''));
        $allowedPrefix = route('admin.knowledge-bases.index', [], false);

        if ($redirectTo !== '' && str_starts_with($redirectTo, $allowedPrefix) && ! str_starts_with($redirectTo, '//')) {
            return redirect()->to($redirectTo);
        }

        return redirect()->route('admin.knowledge-bases.index');
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadKnowledgeBases(): array
    {
        $query = KnowledgeBase::query()
            ->select([
                'id',
                'name',
                'description',
                'file_type',
                'word_count',
                'usage_count',
                'chunk_sync_status',
                'chunk_sync_error',
                'chunk_source_hash',
                'chunk_synced_at',
                'content',
                'created_at',
                'updated_at',
            ])
            ->with('systemBinding')
            ->withCount('chunks as chunk_count')
            ->withCount([
                'chunks as vectorized_chunk_count' => fn ($query) => $query
                    ->whereNotNull('embedding_model_id')
                    ->where('embedding_dimensions', '>', 0),
            ])
            ->orderByDesc('created_at');

        return $query->get()->map(function (KnowledgeBase $knowledgeBase): array {
            $isSystemKnowledge = $knowledgeBase->isSystemManaged();

            return [
                'id' => (int) $knowledgeBase->id,
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'word_count' => (int) ($knowledgeBase->word_count ?? 0),
                'usage_count' => (int) ($knowledgeBase->usage_count ?? 0),
                'chunk_count' => (int) ($knowledgeBase->chunk_count ?? 0),
                'vectorized_chunk_count' => (int) ($knowledgeBase->vectorized_chunk_count ?? 0),
                'chunk_sync_status' => (string) ($knowledgeBase->chunk_sync_status ?? 'idle'),
                'chunk_sync_error' => trim((string) ($knowledgeBase->chunk_sync_error ?? '')),
                'chunk_synced_at' => $knowledgeBase->chunk_synced_at?->format('Y-m-d H:i:s'),
                'created_at' => $knowledgeBase->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
                'is_system' => $isSystemKnowledge,
                'system_health' => $isSystemKnowledge ? $this->systemKnowledgeBases->health($knowledgeBase) : null,
                'official_version' => (string) ($knowledgeBase->systemBinding?->official_version ?? ''),
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
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text,pdf,presentation'],
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

        $this->assertKnowledgeContentSize((string) $payload['content']);

        return $payload;
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
            'file_type' => ['nullable', 'in:markdown,word,text,pdf,presentation'],
            'source_name' => ['nullable', 'string', 'max:150'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'source_type' => ['nullable', 'in:document,website,business,faq,other'],
            'business_line' => ['nullable', 'string', 'max:100'],
            'effective_date' => ['nullable', 'date'],
            'risk_level' => ['nullable', 'in:low,medium,high'],
            'review_status' => ['nullable', 'in:unreviewed,reviewed'],
            'import_action' => ['nullable', 'in:save,save_and_chunk'],
            'knowledge_file' => ['nullable', File::types(['txt', 'md', 'markdown', 'docx', 'pdf', 'pptx'])->extensions(['txt', 'md', 'markdown', 'docx', 'pdf', 'pptx'])->max(8 * 1024)],
            'knowledge_files' => ['nullable', 'array', 'max:10'],
            'knowledge_files.*' => ['file', File::types(['txt', 'md', 'markdown', 'docx', 'pdf', 'pptx'])->extensions(['txt', 'md', 'markdown', 'docx', 'pdf', 'pptx'])->max(8 * 1024)],
        ], [
            'knowledge_file.extensions' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_file.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_file.max' => __('admin.knowledge_bases.error.file_too_large'),
            'knowledge_files.max' => __('admin.knowledge_bases.error.files_limit'),
            'knowledge_files.*.extensions' => __('admin.knowledge_bases.error.file_type_invalid'),
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

    private function createKnowledgeBaseFromRequest(Request $request, string $errorMessageKey): RedirectResponse
    {
        $payload = $this->validateKnowledgeImportForm($request);
        $storedPaths = [];
        $knowledgeBase = null;

        try {
            $manualContent = $this->sourceParser->normalizeKnowledgeText((string) ($payload['content'] ?? ''));
            $uploadedFiles = $this->sourceParser->uploadedKnowledgeFiles($request);
            $this->assertKnowledgeContentSize($manualContent);
            $this->assertUploadedKnowledgeTotalSize($uploadedFiles);

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

            $parsedFiles = $this->sourceParser->parseUploadedKnowledgeFiles($uploadedFiles, $storedPaths);
            $content = $this->sourceParser->mergeKnowledgeSources($manualContent, $parsedFiles);
            $this->assertKnowledgeContentSize($content);
            if ($content === '') {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                ]);
            }

            $knowledgeName = trim((string) ($payload['name'] ?? ''));
            if ($knowledgeName === '') {
                $knowledgeName = $this->sourceParser->inferKnowledgeName($uploadedFiles);
            }
            if ($knowledgeName === '') {
                $knowledgeName = $this->sourceParser->inferKnowledgeNameFromContent($manualContent);
            }
            if ($knowledgeName === '') {
                throw ValidationException::withMessages([
                    'name' => __('admin.knowledge_bases.error.name_required'),
                ]);
            }

            $fileType = $this->sourceParser->resolveKnowledgeFileType(
                (string) ($payload['file_type'] ?? 'markdown'),
                $manualContent,
                $parsedFiles
            );
            $encodedFilePath = $this->sourceParser->encodeKnowledgeFilePaths($storedPaths);

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
                'admin.knowledge-bases.index',
                [],
            );
        } catch (ValidationException $exception) {
            $this->sourceParser->cleanupKnowledgeFiles($storedPaths);

            throw $exception;
        } catch (\Throwable $exception) {
            if ($knowledgeBase instanceof KnowledgeBase) {
                return redirect()
                    ->route('admin.knowledge-bases.index')
                    ->withErrors([
                        'chunk_sync' => __('admin.knowledge_bases.message.chunk_sync_deferred', [
                            'message' => $exception->getMessage(),
                        ]),
                    ]);
            }

            $this->sourceParser->cleanupKnowledgeFiles($storedPaths);

            return back()
                ->withInput($request->except(['knowledge_file', 'knowledge_files']))
                ->withErrors(__('admin.knowledge_bases.message.'.$errorMessageKey, ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 保存知识库后提交后台切片任务。
     *
     * @param  array<string, mixed>  $routeParameters
     */
    private function redirectAfterChunkSync(KnowledgeBase $knowledgeBase, string $routeName, array $routeParameters): RedirectResponse
    {
        try {
            $this->chunkSyncCoordinator->request(
                (int) $knowledgeBase->id,
                SystemAiIdentity::knowledgeIndex(),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route($routeName, $routeParameters)
                ->withErrors([
                    'chunk_sync' => __('admin.knowledge_bases.message.chunk_sync_deferred', [
                        'message' => $exception->getMessage(),
                    ]),
                ]);
        }

        return redirect()
            ->route($routeName, $routeParameters)
            ->with('message', __('admin.knowledge_bases.message.chunk_sync_queued'));
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

    /**
     * @return list<int>
     */
    private function taskIdsUsingKnowledgeBase(int $knowledgeBaseId): array
    {
        $taskIds = Task::withTrashed()
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
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateChunkRows(int $knowledgeBaseId): LengthAwarePaginator
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
            ->paginate(30)
            ->through(static function (KnowledgeChunk $chunk): array {
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
                    'content_preview' => mb_substr(trim((string) $chunk->content), 0, 240, 'UTF-8'),
                ];
            });
    }

    private function assertKnowledgeContentSize(string $content): void
    {
        if (strlen($content) <= self::MAX_KNOWLEDGE_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'content' => __('admin.knowledge_bases.error.content_too_large'),
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    private function assertUploadedKnowledgeTotalSize(array $uploadedFiles): void
    {
        $totalBytes = 0;
        foreach ($uploadedFiles as $uploadedFile) {
            $totalBytes += max(0, (int) $uploadedFile->getSize());
        }

        if ($totalBytes <= self::MAX_KNOWLEDGE_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'knowledge_files' => __('admin.knowledge_bases.error.total_files_too_large'),
        ]);
    }
}
