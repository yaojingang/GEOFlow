<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 特殊提示词配置控制器。
 *
 * 对齐 bak/admin/ai-special-prompts.php：
 * 1. 管理 keyword / description 两类提示词；
 * 2. 支持分别保存关键词提示词与描述提示词；
 * 3. 页面读取各类型最新一条配置用于展示。
 */
class AiSpecialPromptController extends Controller
{
    /**
     * 特殊提示词配置页。
     */
    public function index(): View
    {
        return view('admin.ai-special-prompts.index', [
            'pageTitle' => __('admin.ai_special.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'keywordPromptContent' => $this->loadLatestPromptContent('keyword'),
            'descriptionPromptContent' => $this->loadLatestPromptContent('description'),
        ]);
    }

    /**
     * 保存关键词提示词。
     */
    public function updateKeyword(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'keyword_content' => ['required', 'string'],
        ], [
            'keyword_content.required' => __('admin.ai_special.error.keyword_required'),
        ]);

        $this->upsertPromptByType(
            type: 'keyword',
            content: trim((string) $payload['keyword_content']),
            fallbackName: '关键词生成提示词'
        );

        return redirect()->route('admin.ai-special-prompts')->with('message', __('admin.ai_special.message.keyword_saved'));
    }

    /**
     * 保存文章描述提示词。
     */
    public function updateDescription(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'description_content' => ['required', 'string'],
        ], [
            'description_content.required' => __('admin.ai_special.error.description_required'),
        ]);

        $this->upsertPromptByType(
            type: 'description',
            content: trim((string) $payload['description_content']),
            fallbackName: '文章描述生成提示词'
        );

        return redirect()->route('admin.ai-special-prompts')->with('message', __('admin.ai_special.message.description_saved'));
    }

    /**
     * 获取某类提示词最新内容。
     */
    private function loadLatestPromptContent(string $type): string
    {
        $prompt = Prompt::query()
            ->select(['id', 'content'])
            ->where('type', $type)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $prompt ? (string) $prompt->content : '';
    }

    /**
     * 对齐 bak：若该类型已存在，则批量更新该类型所有记录；否则创建一条默认记录。
     */
    private function upsertPromptByType(string $type, string $content, string $fallbackName): void
    {
        $content = trim($content);

        $exists = Prompt::query()
            ->where('type', $type)
            ->exists();

        if ($exists) {
            // 关键逻辑：与 bak 一致，更新同类型所有提示词，避免历史重复数据出现分叉内容。
            Prompt::query()
                ->where('type', $type)
                ->update([
                    'content' => $content,
                    'updated_at' => now(),
                ]);

            return;
        }

        Prompt::query()->create([
            'name' => $fallbackName,
            'type' => $type,
            'content' => $content,
            'variables' => '',
        ]);
    }
}
