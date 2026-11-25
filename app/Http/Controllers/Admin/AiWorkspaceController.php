<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AiWorkspace\AdminHelpKnowledgeCatalog;
use App\Services\AiWorkspace\AiWorkspaceConnectionStatus;
use App\Support\AdminWeb;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AiWorkspaceController extends Controller
{
    public function __invoke(
        AdminHelpKnowledgeCatalog $catalog,
        AiWorkspaceConnectionStatus $connectionStatus,
    ): View {
        /** @var Admin $admin */
        $admin = auth('admin')->user();
        $connection = $connectionStatus->forAdmin($admin);
        $displayName = trim((string) ($admin->display_name ?: $admin->username));

        return view('admin.ai-workspace.index', [
            'pageTitle' => __('admin.ai_workspace.page_title'),
            'activeMenu' => 'ai-workspace',
            'adminSiteName' => AdminWeb::siteName(),
            'assistantAvailable' => $connection['ready'],
            'assistantConnection' => $connection,
            'starterActions' => $catalog->starterActions($admin),
            'userInitial' => Str::upper(Str::substr($displayName, 0, 1)),
            'aiWorkspaceLabels' => $this->labels(),
        'agentMatrix' => $this->agentMatrix(),
        ]);
    }

    /** @return array<string, mixed> */

    /** @return list<array<string, mixed>> */
    private function agentMatrix(): array
    {
        $zt = $this->callMcpTool('zentao_daily_report');
        $fleet = $this->callMcpTool('agent_fleet_status');
        $n8n = $this->callMcpTool('n8n_status');

        $snapshot = is_array($fleet['snapshot'] ?? null) ? $fleet['snapshot'] : [];
        $online = count($snapshot);
        $issues = is_array($fleet['last_issues'] ?? null) ? $fleet['last_issues'] : [];

        $ztDetail = isset($zt['pending_tasks'])
            ? sprintf('今日新需求 %d · 待办 %d · 开放Bug %d · 逾期 %d', $zt['new_stories'] ?? 0, $zt['pending_tasks'] ?? 0, $zt['open_bugs'] ?? 0, $zt['overdue_tasks'] ?? 0)
            : '暂不可用';

        $agentsDetail = $online > 0
            ? sprintf('%d 台节点在线 · 最近巡检 %s', $online, $fleet['last_run'] ?? '-')
            : '暂不可用';
        if ($issues) {
            $agentsDetail .= ' · 待关注: ' . implode('、', $issues);
        }

        $n8nOnline = !empty($n8n['online']);

        return [
            [
                'id' => 'mcp',
                'name' => 'MCP 智能体矩阵',
                'icon' => 'bot',
                'status' => 'active',
                'detail' => '28 个内容 / 审核 / 分发 / 监控 / 线索 / 智能体工具在线',
                'url' => '/geoflow/mcp/',
                'external' => false,
            ],
            [
                'id' => 'zentao',
                'name' => '禅道数字员工',
                'icon' => 'briefcase',
                'status' => 'active',
                'detail' => $ztDetail,
                'url' => 'https://3.aixgn.cn',
                'external' => true,
            ],
            [
                'id' => 'agents',
                'name' => '数字员工矩阵',
                'icon' => 'users-round',
                'status' => $online > 0 ? 'active' : 'offline',
                'detail' => $agentsDetail,
                'url' => null,
                'external' => false,
            ],
            [
                'id' => 'ops',
                'name' => '运维智能体',
                'icon' => 'server',
                'status' => 'active',
                'detail' => '服务端口 / 进程 / 域名健康巡检',
                'url' => null,
                'external' => false,
            ],
            [
                'id' => 'n8n',
                'name' => 'n8n 工作流编排',
                'icon' => 'workflow',
                'status' => $n8nOnline ? 'active' : 'pending',
                'detail' => $n8nOnline ? '138 个工作流 · 38 个 webhook 在线编排' : '引擎待接入',
                'url' => 'https://n8n.aixgn.cn/',
                'external' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function callMcpTool(string $tool, array $args = []): array
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ], JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json
",
                'content' => $payload,
                'timeout' => 4,
            ],
        ]);

        $raw = @file_get_contents('http://127.0.0.1:18090/', false, $context);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? ($data['result'] ?? ($data['error'] ?? [])) : [];
    }

    private function labels(): array
    {
        $keys = [
            'connectionChecking' => 'connection_checking',
            'connectionSuccess' => 'connection_success',
            'connectionFailed' => 'connection_failed',
            'connectionRetry' => 'connection_retry',
            'connectionRateLimited' => 'connection_rate_limited',
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
        $labels['task'] = __('ai-task.ui');
        $labels['dialogCancel'] = (string) __('admin.action_dialog.cancel');
        $labels['dialogRequired'] = (string) __('admin.action_dialog.required');

        return $labels;
    }
}
