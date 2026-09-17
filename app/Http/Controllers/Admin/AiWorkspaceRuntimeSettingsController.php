<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiWorkspaceRuntimeRequest;
use App\Services\AiWorkspace\AiWorkspaceRuntimeStatus;
use Illuminate\Http\RedirectResponse;

final class AiWorkspaceRuntimeSettingsController extends Controller
{
    public function __invoke(
        UpdateAiWorkspaceRuntimeRequest $request,
        AiWorkspaceRuntimeStatus $runtimeStatus,
    ): RedirectResponse {
        $runtimeStatus->save($request->enabled());
        $snapshot = $runtimeStatus->snapshot();
        $auditDetails = [
            'preferred_enabled' => $snapshot['preferred_enabled'],
            'effective_enabled' => $snapshot['effective_enabled'],
            'forced_disabled' => $snapshot['forced_disabled'],
        ];
        $request->attributes->set('admin_activity_action', 'save');
        $request->attributes->set('admin_activity_details', $auditDetails);
        request()->attributes->set('admin_activity_action', 'save');
        request()->attributes->set('admin_activity_details', $auditDetails);

        $message = $snapshot['forced_disabled']
            ? __('admin.site_settings.ai_workspace_runtime.saved_forced_disabled')
            : ($snapshot['effective_enabled']
                ? __('admin.site_settings.ai_workspace_runtime.saved_enabled')
                : __('admin.site_settings.ai_workspace_runtime.saved_disabled'));

        return redirect()
            ->route('admin.site-settings.index')
            ->with('message', $message)
            ->with('site_settings_open_target', 'site-settings-ai-workspace');
    }
}
