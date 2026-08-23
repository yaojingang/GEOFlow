<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Http\Controllers\Controller;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Support\Admin\AiWorkspaceCapabilityPresenter;
use App\Support\AdminWeb;
use Illuminate\View\View;

final class AiWorkspaceController extends Controller
{
    public function __invoke(
        AiCapabilityRegistry $registry,
        AiWorkspaceModelReadiness $readiness,
        AiWorkspaceCapabilityPresenter $presenter,
    ): View {
        $modelStatus = $readiness->status();
        $configured = (bool) config('ai-workspace.runtime_enabled', false);
        $capabilities = $presenter->present($registry->visibleTo(auth('admin')->user()));

        return view('admin.ai-workspace.index', [
            'pageTitle' => __('admin.ai_workspace.page_title'),
            'activeMenu' => 'ai-workspace',
            'adminSiteName' => AdminWeb::siteName(),
            'runtimeEnabled' => $configured && $modelStatus['ready'],
            'runtimeConfigured' => $configured,
            'runtimeUnavailableReason' => $modelStatus['reason'],
            'capabilityCount' => $capabilities['count'],
            'capabilityGroups' => $capabilities['groups'],
            'featuredCapabilities' => $capabilities['featured'],
            'aiWorkspaceLabels' => $this->labels(),
        ]);
    }

    /** @return array<string,mixed> */
    private function labels(): array
    {
        $keys = [
            'newConversation' => 'new_conversation', 'historyEmpty' => 'history_empty', 'archive' => 'archive',
            'planTitle' => 'plan_title', 'approve' => 'approve', 'reject' => 'reject', 'editPlan' => 'edit_plan',
            'savePlan' => 'save_plan', 'cancel' => 'cancel', 'retry' => 'retry', 'openResult' => 'open_result',
            'systemNotExecuted' => 'system_not_executed', 'sources' => 'sources', 'sessionExpired' => 'session_expired',
            'networkError' => 'network_error', 'invalidJson' => 'invalid_json', 'send' => 'send',
            'runtimeUnavailable' => 'runtime_unavailable', 'messageSending' => 'message_sending',
            'activityTitle' => 'activity_title', 'submissionPending' => 'submission_pending', 'stepRunning' => 'step_running',
            'errorDialogEyebrow' => 'error_dialog_eyebrow', 'errorTitle' => 'error_title', 'errorHint' => 'error_hint',
            'errorRuntimeTitle' => 'error_runtime_title', 'errorRuntimeDescription' => 'error_runtime_description',
            'errorRuntimeHint' => 'error_runtime_hint', 'errorSessionTitle' => 'error_session_title',
            'errorSessionDescription' => 'error_session_description', 'errorSessionHint' => 'error_session_hint',
            'errorNetworkTitle' => 'error_network_title', 'errorNetworkDescription' => 'error_network_description',
            'errorNetworkHint' => 'error_network_hint', 'continueEditing' => 'continue_editing',
            'openConfigurator' => 'open_configurator', 'refreshPage' => 'refresh_page', 'returnToPage' => 'return_to_page',
        ];
        $labels = collect($keys)->mapWithKeys(
            static fn (string $key, string $name): array => [$name => __('admin.ai_workspace.'.$key)]
        )->all();
        $labels['planVersion'] = __('admin.ai_workspace.plan_version', ['version' => ':version']);
        $labels['intentConfidence'] = __('admin.ai_workspace.intent_confidence');
        $labels['risks'] = collect(['low', 'medium', 'high', 'critical'])->mapWithKeys(static fn (string $risk): array => [
            $risk => __('admin.ai_workspace.risk_'.$risk),
        ])->all();
        $labels['scopes'] = collect(['internal_read', 'internal_write', 'external_read', 'external_write'])->mapWithKeys(static fn (string $scope): array => [
            $scope => __('admin.ai_workspace.scope_'.$scope),
        ])->all();
        $labels['statuses'] = collect([
            'received', 'clarifying', 'answering', 'planning', 'validating_plan', 'awaiting_approval', 'awaiting_step_approval', 'queued',
            'running', 'partially_completed', 'failed', 'skipped', 'cancel_requested', 'cancelled', 'outcome_unknown', 'rejected',
        ])->mapWithKeys(static fn (string $state): array => [
            $state => __('admin.ai_workspace.status_'.$state),
        ])->all();
        $labels['statuses']['completed'] = __('admin.ai_workspace.status_completed_full');
        $labels['activityStages'] = collect(['intake', 'planning', 'execution'])->mapWithKeys(static fn (string $stage): array => [
            $stage => __('admin.ai_workspace.activity_'.$stage),
        ])->all();

        return $labels;
    }
}
