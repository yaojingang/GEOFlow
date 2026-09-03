<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AiWorkspace\AdminHelpKnowledgeCatalog;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Support\AdminWeb;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AiWorkspaceController extends Controller
{
    public function __invoke(
        AdminHelpKnowledgeCatalog $catalog,
        AiWorkspaceModelReadiness $readiness,
    ): View {
        /** @var Admin $admin */
        $admin = auth('admin')->user();
        $modelStatus = $readiness->status($admin);
        $displayName = trim((string) ($admin->display_name ?: $admin->username));

        return view('admin.ai-workspace.index', [
            'pageTitle' => __('admin.ai_workspace.page_title'),
            'activeMenu' => 'ai-workspace',
            'adminSiteName' => AdminWeb::siteName(),
            'assistantAvailable' => (bool) config('ai-workspace.runtime_enabled', false) && $modelStatus['ready'],
            'starterActions' => $catalog->starterActions($admin),
            'userInitial' => Str::upper(Str::substr($displayName, 0, 1)),
            'aiWorkspaceLabels' => $this->labels(),
        ]);
    }

    /** @return array<string, mixed> */
    private function labels(): array
    {
        $keys = [
            'copyAnswer' => 'copy_answer',
            'copyCode' => 'copy_code',
            'copied' => 'copied',
            'copyFailed' => 'copy_failed',
            'relatedFeatures' => 'related_features',
            'referenceSections' => 'reference_sections',
            'knowledgeImages' => 'knowledge_images',
            'closePreview' => 'close_preview',
            'zoomOut' => 'zoom_out',
            'zoomIn' => 'zoom_in',
            'resetZoom' => 'reset_zoom',
            'zoomLevel' => 'zoom_level',
            'suggestedQuestions' => 'suggested_questions',
            'loadEarlier' => 'load_earlier',
            'loadingEarlier' => 'loading_earlier',
            'networkError' => 'network_error',
            'sessionExpired' => 'session_expired',
            'answerStopped' => 'answer_stopped',
            'statusSlow' => 'status_slow',
            'statusVerySlow' => 'status_very_slow',
            'answerComplete' => 'answer_complete',
            'assistantRole' => 'assistant_role',
            'userRole' => 'user_role',
            'renamePrompt' => 'rename_prompt',
            'defaultTitle' => 'new_conversation_default',
            'casualConversationTitle' => 'casual_conversation_title',
        ];

        $labels = collect($keys)->mapWithKeys(
            static fn (string $key, string $name): array => [$name => (string) __('admin.ai_workspace.'.$key)]
        )->all();
        $labels['defaultTitles'] = collect(array_keys(AdminWeb::supportedLocales()))
            ->map(static fn (string $locale): string => (string) __('admin.ai_workspace.new_conversation_default', [], $locale))
            ->unique()
            ->values()
            ->all();
        $labels['dialogCancel'] = (string) __('admin.action_dialog.cancel');
        $labels['dialogRequired'] = (string) __('admin.action_dialog.required');

        return $labels;
    }
}
