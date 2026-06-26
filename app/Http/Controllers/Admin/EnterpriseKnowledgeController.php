<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\GeoFlow\KnowledgeSourceParser;
use App\Support\AdminWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class EnterpriseKnowledgeController extends Controller
{
    public function __construct(
        private readonly KnowledgeSourceParser $sourceParser,
        private readonly EnterpriseKnowledgeDraftService $draftService,
    ) {}

    public function index(): View
    {
        $projects = EnterpriseKnowledgeProject::query()
            ->with(['publishedKnowledgeBase'])
            ->withCount(['sources', 'revisions'])
            ->latest()
            ->paginate(20);

        return view('admin.enterprise-knowledge.index', [
            'pageTitle' => __('admin.enterprise_knowledge.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'projects' => $projects,
        ]);
    }

    public function create(): View
    {
        return view('admin.enterprise-knowledge.create', [
            'pageTitle' => __('admin.enterprise_knowledge.create_heading'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string'],
            'enterprise_files' => ['nullable', 'array', 'max:10'],
            'enterprise_files.*' => ['file', File::types(['txt', 'md', 'markdown', 'docx'])->max(50 * 1024)],
        ], [
            'enterprise_files.max' => __('admin.enterprise_knowledge.error.files_limit'),
        ]);

        $manualContent = $this->sourceParser->normalizeKnowledgeText((string) ($payload['content'] ?? ''));
        $uploadedFiles = $this->sourceParser->uploadedFilesFromFields($request, ['enterprise_files']);

        if ($manualContent === '' && $uploadedFiles === []) {
            throw ValidationException::withMessages([
                'content' => __('admin.enterprise_knowledge.error.content_required'),
            ]);
        }

        $storedPaths = [];
        try {
            $parsedFiles = $this->sourceParser->parseUploadedKnowledgeFiles($uploadedFiles, $storedPaths, 'uploads/enterprise-knowledge');
            $mergedContent = $this->sourceParser->mergeKnowledgeSources($manualContent, $parsedFiles);
            $name = trim((string) ($payload['name'] ?? ''));
            $name = $name !== ''
                ? $name
                : ($this->sourceParser->inferKnowledgeName($uploadedFiles) ?: $this->sourceParser->inferKnowledgeNameFromContent($mergedContent));

            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => __('admin.enterprise_knowledge.error.name_required'),
                ]);
            }

            $project = DB::transaction(function () use ($name, $payload, $manualContent, $parsedFiles): EnterpriseKnowledgeProject {
                $project = EnterpriseKnowledgeProject::query()->create([
                    'name' => $name,
                    'description' => trim((string) ($payload['description'] ?? '')),
                    'status' => 'queued',
                    'structured_json' => $this->initialDraftProgressJson(),
                    'created_by_admin_id' => auth('admin')->id(),
                ]);

                $sortOrder = 0;
                if ($manualContent !== '') {
                    EnterpriseKnowledgeSource::query()->create([
                        'enterprise_knowledge_project_id' => (int) $project->id,
                        'original_name' => __('admin.enterprise_knowledge.manual_source_name'),
                        'file_type' => 'markdown',
                        'content' => $manualContent,
                        'character_count' => mb_strlen($manualContent, 'UTF-8'),
                        'sort_order' => $sortOrder++,
                    ]);
                }

                foreach ($parsedFiles as $file) {
                    $content = (string) ($file['content'] ?? '');
                    EnterpriseKnowledgeSource::query()->create([
                        'enterprise_knowledge_project_id' => (int) $project->id,
                        'original_name' => (string) ($file['original_name'] ?? ''),
                        'file_path' => (string) ($file['file_path'] ?? $file['stored_path'] ?? ''),
                        'file_type' => (string) ($file['file_type'] ?? 'text'),
                        'content' => $content,
                        'character_count' => mb_strlen($content, 'UTF-8'),
                        'sort_order' => $sortOrder++,
                    ]);
                }

                return $project;
            });

            GenerateEnterpriseKnowledgeDraftJob::dispatch((int) $project->id, auth('admin')->id())->onQueue('geoflow');

            return redirect()
                ->route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id])
                ->with('message', __('admin.enterprise_knowledge.message.queued'));
        } catch (ValidationException $exception) {
            $this->sourceParser->cleanupKnowledgeFiles($storedPaths);
            throw $exception;
        } catch (Throwable $exception) {
            $this->sourceParser->cleanupKnowledgeFiles($storedPaths);

            return back()
                ->withInput()
                ->withErrors(__('admin.enterprise_knowledge.error.create_failed', ['message' => $exception->getMessage()]));
        }
    }

    public function show(int $projectId): View
    {
        $project = EnterpriseKnowledgeProject::query()
            ->with(['sources', 'revisions.creator', 'publishedKnowledgeBase'])
            ->whereKey($projectId)
            ->firstOrFail();

        return view('admin.enterprise-knowledge.show', [
            'pageTitle' => (string) $project->name,
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'project' => $project,
            'validationItems' => $project->validationItems(),
        ]);
    }

    public function status(int $projectId): JsonResponse
    {
        $project = EnterpriseKnowledgeProject::query()
            ->withCount(['sources', 'revisions'])
            ->whereKey($projectId)
            ->firstOrFail();

        $progress = $project->draftGenerationProgress();
        $status = (string) ($project->status ?? 'draft');
        $draftReady = trim((string) ($project->draft_content ?? '')) !== '';
        $fallbackProgress = match ($status) {
            'queued' => 8,
            'processing' => 45,
            'reviewing', 'published' => 100,
            'failed' => 100,
            default => 0,
        };
        $fallbackMessage = $status === 'failed'
            ? __('admin.enterprise_knowledge.progress_message.failed', [
                'message' => (string) ($project->error_message ?: __('admin.enterprise_knowledge.status_failed')),
            ])
            : $this->enterpriseKnowledgeTranslation(
                'admin.enterprise_knowledge.progress_message.'.$status,
                __('admin.enterprise_knowledge.progress_message.queued')
            );
        $statusLabel = $this->enterpriseKnowledgeTranslation(
            'admin.enterprise_knowledge.status_'.$status,
            $status
        );

        return response()->json([
            'status' => $status,
            'status_label' => $statusLabel,
            'draft_ready' => $draftReady,
            'reload' => in_array($status, ['reviewing', 'published', 'failed'], true),
            'error_message' => (string) ($project->error_message ?? ''),
            'sources_count' => (int) $project->sources_count,
            'revisions_count' => (int) $project->revisions_count,
            'progress' => [
                'step' => (string) ($progress['step'] ?? $status),
                'progress' => (int) ($progress['progress'] ?? $fallbackProgress),
                'message' => (string) ($progress['message'] ?? $fallbackMessage),
                'updated_at' => (string) ($progress['updated_at'] ?? optional($project->updated_at)->toIso8601String()),
            ],
        ]);
    }

    public function autosave(Request $request, int $projectId): JsonResponse
    {
        $project = EnterpriseKnowledgeProject::query()->whereKey($projectId)->firstOrFail();
        $payload = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $content = trim((string) $payload['content']);
        $validationItems = $this->draftService->validateDraft($content);
        $project->update([
            'draft_content' => $content,
            'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
            'status' => $project->status === 'published' ? 'reviewing' : (string) $project->status,
        ]);
        $this->recordRevisionIfChanged($project, $content, 'manual', __('admin.enterprise_knowledge.revision_manual'));

        return response()->json([
            'saved_at' => now()->format('Y-m-d H:i:s'),
            'validation_count' => count($validationItems),
            'validation_items' => $validationItems,
        ]);
    }

    public function validateDraft(Request $request, int $projectId): RedirectResponse|JsonResponse
    {
        $project = EnterpriseKnowledgeProject::query()->whereKey($projectId)->firstOrFail();
        $content = trim((string) $request->input('content', $project->draft_content ?? ''));
        $validationItems = $this->draftService->validateDraft($content);
        $project->update([
            'draft_content' => $content,
            'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'validation_count' => count($validationItems),
                'validation_items' => $validationItems,
            ]);
        }

        return back()->with('message', __('admin.enterprise_knowledge.message.validated'));
    }

    public function uploadImage(Request $request, int $projectId): JsonResponse
    {
        EnterpriseKnowledgeProject::query()->whereKey($projectId)->firstOrFail();

        try {
            $payload = $request->validate([
                'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
                'alt' => ['nullable', 'string', 'max:120'],
            ], [
                'image.required' => __('admin.enterprise_knowledge.error.image_required'),
                'image.image' => __('admin.enterprise_knowledge.error.image_invalid'),
                'image.mimes' => __('admin.enterprise_knowledge.error.image_invalid'),
                'image.max' => __('admin.enterprise_knowledge.error.image_too_large'),
            ]);

            $file = $request->file('image');
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'image' => __('admin.enterprise_knowledge.error.upload_missing'),
                ]);
            }

            $stored = $this->storeEditorImageFile($file, (int) $projectId);
            $alt = $this->normalizeImageAlt((string) ($payload['alt'] ?? ''));
            if ($alt === '') {
                $alt = $this->readableImageAlt($file->getClientOriginalName());
            }

            $url = Storage::disk('public')->url((string) $stored['path']);

            return response()->json([
                'message' => __('admin.enterprise_knowledge.editor_upload_success'),
                'image' => [
                    'url' => $url,
                    'storage_path' => (string) $stored['path'],
                    'file_path' => 'storage/'.(string) $stored['path'],
                    'original_name' => $file->getClientOriginalName(),
                    'alt' => $alt,
                    'markdown' => '!['.$this->escapeMarkdownAlt($alt).']('.$url.')',
                    'width' => (int) $stored['width'],
                    'height' => (int) $stored['height'],
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('admin.enterprise_knowledge.error.upload_failed', [
                    'message' => $exception->getMessage(),
                ]),
            ], 422);
        }
    }

    public function restoreRevision(int $projectId, int $revisionId): RedirectResponse
    {
        $project = EnterpriseKnowledgeProject::query()->whereKey($projectId)->firstOrFail();
        $revision = EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', (int) $project->id)
            ->whereKey($revisionId)
            ->firstOrFail();

        $content = (string) $revision->content;
        $project->update([
            'draft_content' => $content,
            'validation_json' => json_encode($this->draftService->validateDraft($content), JSON_UNESCAPED_UNICODE),
            'status' => 'reviewing',
        ]);
        $this->recordRevision($project, $content, 'restore', __('admin.enterprise_knowledge.revision_restore'));

        return back()->with('message', __('admin.enterprise_knowledge.message.restored'));
    }

    public function publish(int $projectId): RedirectResponse
    {
        $project = EnterpriseKnowledgeProject::query()->whereKey($projectId)->firstOrFail();
        $content = trim((string) ($project->draft_content ?? ''));
        if ($content === '') {
            return back()->withErrors(__('admin.enterprise_knowledge.error.content_required'));
        }

        $result = $this->draftService->publishToKnowledgeBase($project, $content);
        $knowledgeBase = $result['knowledge_base'];
        $project->update([
            'status' => 'published',
            'published_knowledge_base_id' => (int) $knowledgeBase->id,
            'validation_json' => json_encode($this->draftService->validateDraft($content), JSON_UNESCAPED_UNICODE),
            'error_message' => $result['chunk_error'],
        ]);
        $this->recordRevision($project, $content, 'publish', __('admin.enterprise_knowledge.revision_publish'));

        if ((string) $result['chunk_error'] !== '') {
            return back()->withErrors(__('admin.enterprise_knowledge.message.published_with_chunk_error', [
                'message' => (string) $result['chunk_error'],
            ]));
        }

        return redirect()
            ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id])
            ->with('message', __('admin.enterprise_knowledge.message.published', ['count' => (int) $result['chunk_count']]));
    }

    public function destroy(int $projectId): RedirectResponse
    {
        $project = EnterpriseKnowledgeProject::query()->with('sources')->whereKey($projectId)->firstOrFail();
        $this->sourceParser->cleanupKnowledgeFiles($project->sources->pluck('file_path')->filter()->map(static fn ($path): string => (string) $path)->values()->all());
        Storage::disk('public')->deleteDirectory('uploads/enterprise-knowledge/'.(int) $project->id);
        $project->delete();

        return redirect()
            ->route('admin.enterprise-knowledge.index')
            ->with('message', __('admin.enterprise_knowledge.message.deleted'));
    }

    private function recordRevisionIfChanged(EnterpriseKnowledgeProject $project, string $content, string $source, string $summary): void
    {
        $hash = hash('sha256', $content);
        $latestHash = (string) EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', (int) $project->id)
            ->latest()
            ->value('content_hash');

        if ($latestHash === $hash) {
            return;
        }

        $this->recordRevision($project, $content, $source, $summary, $hash);
    }

    private function recordRevision(EnterpriseKnowledgeProject $project, string $content, string $source, string $summary, ?string $hash = null): void
    {
        EnterpriseKnowledgeRevision::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'content' => $content,
            'summary' => $summary,
            'source' => $source,
            'created_by_admin_id' => auth('admin')->id(),
            'content_hash' => $hash ?? hash('sha256', $content),
        ]);
    }

    private function storeEditorImageFile(UploadedFile $file, int $projectId): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException(__('admin.enterprise_knowledge.error.upload_missing'));
        }

        $directory = 'uploads/enterprise-knowledge/'.$projectId.'/images/'.now()->format('Y/m');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        $path = $file->storeAs($directory, bin2hex(random_bytes(16)).'.'.$extension, 'public');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException(__('admin.enterprise_knowledge.error.upload_store_failed'));
        }

        $size = @getimagesize($file->getRealPath());

        return [
            'path' => $path,
            'width' => is_array($size) ? (int) ($size[0] ?? 0) : 0,
            'height' => is_array($size) ? (int) ($size[1] ?? 0) : 0,
        ];
    }

    private function readableImageAlt(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        return $this->normalizeImageAlt(str_replace(['-', '_'], ' ', $name));
    }

    private function normalizeImageAlt(string $alt): string
    {
        $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?: '');

        return mb_substr($alt, 0, 120, 'UTF-8');
    }

    private function escapeMarkdownAlt(string $alt): string
    {
        return str_replace([']', "\n", "\r"], ['\\]', ' ', ' '], $alt);
    }

    private function initialDraftProgressJson(): string
    {
        return json_encode([
            'draft_generation' => [
                'step' => 'queued',
                'progress' => 8,
                'message' => __('admin.enterprise_knowledge.progress_message.queued'),
                'started_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function enterpriseKnowledgeTranslation(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }
}
