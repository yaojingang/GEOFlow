<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Models\Task;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
     * 创建正文提示词。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.required'),
            'content.required' => __('admin.ai_prompts.error.required'),
        ]);

        Prompt::query()->create([
            'name' => trim((string) $payload['name']),
            'type' => 'content',
            'content' => trim((string) $payload['content']),
            'variables' => '',
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
            ->where('type', 'content')
            ->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.invalid_fields'),
            'content.required' => __('admin.ai_prompts.error.invalid_fields'),
        ]);

        $prompt->update([
            'name' => trim((string) $payload['name']),
            'content' => trim((string) $payload['content']),
        ]);

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.update_success'));
    }

    /**
     * 删除正文提示词（任务引用保护）。
     */
    public function destroy(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->where('type', 'content')
            ->firstOrFail();

        $usageCount = Task::query()->where('prompt_id', $promptId)->count();
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
            ->select(['id', 'name', 'type', 'content', 'created_at'])
            ->where('type', 'content')
            ->withCount('tasks')
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (Prompt $prompt): array {
                return [
                    'id' => (int) $prompt->id,
                    'name' => (string) $prompt->name,
                    'content' => (string) $prompt->content,
                    'task_count' => (int) ($prompt->tasks_count ?? 0),
                    'created_at' => optional($prompt->created_at)?->format('Y-m-d H:i'),
                ];
            })
            ->all();
    }
}
