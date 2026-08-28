<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleAiQualityPromptRenderer;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 正文提示词配置控制器。
 *
 * 对齐 bak/admin/ai-prompts.php：
 * 1. 仅管理 type=content 的提示词；
 * 2. 支持创建、编辑、删除；
 * 3. 展示任务引用数量，删除时做引用保护。
 */
class AiPromptController extends Controller
{
    public function __construct(
        private readonly ArticleAiQualityPromptRenderer $qualityPromptRenderer,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
    ) {}

    /**
     * 正文提示词列表页。
     */
    public function index(): View
    {
        return view('admin.ai-prompts.index', [
            'pageTitle' => __('admin.ai_prompts.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'prompts' => $this->loadPrompts(),
        ]);
    }

    /**
     * 正文提示词创建页。
     */
    public function create(): View
    {
        return view('admin.ai-prompts.create', [
            'pageTitle' => __('admin.ai_prompts.modal_create'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    /**
     * 正文提示词编辑页。
     */
    public function edit(int $promptId): View
    {
        $prompt = Prompt::query()
            ->select(['id', 'name', 'type', 'content', 'system_key', 'system_version'])
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();

        return view('admin.ai-prompts.edit', [
            'pageTitle' => __('admin.ai_prompts.modal_edit'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'prompt' => $prompt,
        ]);
    }

    /**
     * 创建正文提示词。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:content,quality_check'],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.required'),
            'content.required' => __('admin.ai_prompts.error.required'),
        ]);

        $type = (string) ($payload['type'] ?? 'content');
        $content = trim((string) $payload['content']);
        if ($type === 'quality_check') {
            $this->validateQualityTemplate($content);
        }

        Prompt::query()->create([
            'name' => trim((string) $payload['name']),
            'type' => $type,
            'content' => $content,
            'variables' => $type === 'quality_check'
                ? json_encode($this->qualityVariables(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : '',
        ]);

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.create_success'));
    }

    /**
     * 更新正文提示词。
     */
    public function update(Request $request, int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();

        if (filled($prompt->system_key)) {
            return back()->withErrors('系统内置质检方案为只读，请复制后再修改。');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.invalid_fields'),
            'content.required' => __('admin.ai_prompts.error.invalid_fields'),
        ]);

        $content = trim((string) $payload['content']);
        if ($prompt->type === 'quality_check') {
            $this->validateQualityTemplate($content);
        }

        $prompt->update([
            'name' => trim((string) $payload['name']),
            'content' => $content,
        ]);

        if ($prompt->type === 'quality_check') {
            $this->qualityInvalidationService->invalidatePrompt((int) $prompt->id, 'AI 质检方案已更新');
        }

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.update_success'));
    }

    public function copy(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();
        $copy = Prompt::query()->create([
            'name' => mb_substr((string) $prompt->name.'（副本）', 0, 100, 'UTF-8'),
            'type' => (string) $prompt->type,
            'content' => (string) $prompt->content,
            'variables' => (string) ($prompt->variables ?? ''),
            'system_key' => null,
            'system_version' => null,
        ]);

        return redirect()
            ->route('admin.ai-prompts.edit', ['promptId' => $copy->id])
            ->with('message', '提示词副本已创建，可以直接编辑。');
    }

    /**
     * 删除正文提示词（任务引用保护）。
     */
    public function destroy(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();

        if (filled($prompt->system_key)) {
            return back()->withErrors('系统内置质检方案不能删除，可以复制为自定义方案。');
        }

        $usageCount = Task::withTrashed()
            ->where($prompt->type === 'quality_check' ? 'ai_quality_prompt_id' : 'prompt_id', $promptId)
            ->count();
        if ($usageCount > 0) {
            return back()->withErrors(__('admin.ai_prompts.error.in_use', ['count' => $usageCount]));
        }

        $prompt->delete();

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.delete_success'));
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   name:string,
     *   content:string,
     *   task_count:int,
     *   created_at:?string
     * }>
     */
    private function loadPrompts(): array
    {
        return Prompt::query()
            ->select(['id', 'name', 'type', 'content', 'system_key', 'system_version', 'created_at'])
            ->whereIn('type', ['content', 'quality_check'])
            ->withCount([
                'tasks' => fn ($query) => $query->withTrashed(),
                'qualityTasks' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (Prompt $prompt): array {
                return [
                    'id' => (int) $prompt->id,
                    'name' => (string) $prompt->name,
                    'content' => (string) $prompt->content,
                    'type' => (string) $prompt->type,
                    'task_count' => $prompt->type === 'quality_check'
                        ? (int) ($prompt->quality_tasks_count ?? 0)
                        : (int) ($prompt->tasks_count ?? 0),
                    'system_managed' => filled($prompt->system_key),
                    'system_version' => (string) ($prompt->system_version ?? ''),
                    'created_at' => optional($prompt->created_at)?->format('Y-m-d H:i'),
                ];
            })
            ->all();
    }

    private function validateQualityTemplate(string $content): void
    {
        $variables = array_fill_keys($this->qualityVariables(), 'sample');
        try {
            $this->qualityPromptRenderer->render($content, $variables);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'content' => '质检提示词包含未知变量或模板格式无效：'.$exception->getMessage(),
            ]);
        }
    }

    /** @return list<string> */
    private function qualityVariables(): array
    {
        return [
            'article_title', 'article_excerpt', 'article_outline', 'article_content', 'keywords',
            'meta_description', 'fact_candidates', 'knowledge', 'advertising_rules', 'inspection_date',
            'publication_context', 'segment_index', 'segment_count', 'segment_start_offset',
        ];
    }
}
