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
use Illuminate\Support\Facades\Storage;
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
        DB::transaction(function () use ($knowledgeBase, $payload, $content): void {
            $knowledgeBase->update([
                'name' => trim((string) $payload['name']),
                'description' => trim((string) ($payload['description'] ?? '')),
                'content' => $content,
                'file_type' => (string) $payload['file_type'],
                'character_count' => mb_strlen($content, 'UTF-8'),
                'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            ]);

            $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);
        });

        return redirect()->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])->with(
            'message',
            __('admin.knowledge_bases.message.update_success', ['count' => $this->countChunks($knowledgeBaseId)])
        );
    }

    /**
     * 上传知识文档并写入知识库。
     */
    public function uploadFile(Request $request): RedirectResponse
    {
        $request->validate([
            'knowledge_file' => ['required', File::types(['txt', 'md', 'docx'])->max(10 * 1024)],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'knowledge_file.required' => __('admin.knowledge_bases.error.file_required'),
        ]);

        /** @var UploadedFile|null $knowledgeFile */
        $knowledgeFile = $request->file('knowledge_file');
        if (! $knowledgeFile instanceof UploadedFile) {
            return back()->withErrors(__('admin.knowledge_bases.error.file_required'));
        }

        $storedRelativePath = '';
        try {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($knowledgeFile);
            $parsed = $this->parseUploadedKnowledgeFile(Storage::disk('local')->path($storedRelativePath), $knowledgeFile->getClientOriginalName());
            $content = trim($parsed['content']);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            $knowledgeName = trim((string) $request->input('name', ''));
            if ($knowledgeName === '') {
                $knowledgeName = pathinfo((string) $knowledgeFile->getClientOriginalName(), PATHINFO_FILENAME);
            }

            $chunkCount = 0;
            DB::transaction(function () use (&$chunkCount, $knowledgeName, $request, $content, $parsed, $storedRelativePath): void {
                $knowledgeBase = KnowledgeBase::query()->create([
                    'name' => $knowledgeName,
                    'description' => trim((string) $request->input('description', '')),
                    'content' => $content,
                    'file_type' => $parsed['file_type'],
                    'character_count' => mb_strlen($content, 'UTF-8'),
                    'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
                    'usage_count' => 0,
                    'used_task_count' => 0,
                    'file_path' => $storedRelativePath,
                ]);
                $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);
            });

            return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.upload_success', ['count' => $chunkCount]));
        } catch (\Throwable $exception) {
            $this->cleanupKnowledgeFile($storedRelativePath);

            return back()->withErrors(__('admin.knowledge_bases.message.upload_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 创建知识库并同步 chunks。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateKnowledgeForm($request);

        $content = trim((string) $payload['content']);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'usage_count' => 0,
            'used_task_count' => 0,
            'file_path' => '',
        ]);

        $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

        return redirect()
            ->route('admin.knowledge-bases.index')
            ->with('message', __('admin.knowledge_bases.message.create_success', ['count' => $chunkCount]));
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
        ]);

        $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

        return redirect()
            ->route('admin.knowledge-bases.index')
            ->with('message', __('admin.knowledge_bases.message.update_success', ['count' => $chunkCount]));
    }

    /**
     * 删除知识库（存在任务引用时阻止）。
     */
    public function destroy(int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $taskCount = Task::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.knowledge_bases.error.in_use', ['count' => $taskCount]));
        }

        $filePath = (string) ($knowledgeBase->file_path ?? '');
        $knowledgeBase->delete();
        $this->cleanupKnowledgeFile($filePath);

        return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.delete_success'));
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadKnowledgeBases(): array
    {
        $query = KnowledgeBase::query()
            ->select(['id', 'name', 'description', 'file_type', 'word_count', 'usage_count', 'created_at', 'updated_at'])
            ->withCount('chunks as chunk_count')
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
     * @return array{name:string,description:?string,content:string,file_type:string}
     */
    private function validateKnowledgeForm(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
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
        ];
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
        return Task::query()
            ->select(['id', 'name', 'status', 'updated_at'])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
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
                    'content_preview' => $preview,
                ];
            });
    }

    /**
     * 统计指定知识库的 chunk 数，给提示文案使用。
     */
    private function countChunks(int $knowledgeBaseId): int
    {
        return KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
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
