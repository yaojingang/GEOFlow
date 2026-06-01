<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUpdateRun;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\SystemUpdateBackupService;
use App\Services\Admin\SystemUpdatePlanService;
use App\Services\Admin\SystemUpdateStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemUpdateController extends Controller
{
    public function index(SystemUpdateStateService $stateService): View
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        return view('admin.system-updates.index', [
            'pageTitle' => __('admin.system_updates.page_title'),
            'activeMenu' => 'dashboard',
            'summary' => $stateService->summary(),
        ]);
    }

    public function check(AdminUpdateMetadataService $metadataService): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        $metadataService->forgetCachedMetadata();
        $metadataService->fetchState();

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.checked'));
    }

    public function plan(SystemUpdatePlanService $planService): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        try {
            $planService->createPlan(request()->user('admin'));
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.plan_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.plan_created'));
    }

    public function backup(Request $request, SystemUpdateBackupService $backupService): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'run_uuid' => ['required', 'string', 'max:64'],
        ]);

        $run = SystemUpdateRun::query()
            ->where('run_uuid', (string) $validated['run_uuid'])
            ->where('action', 'plan')
            ->where('status', 'succeeded')
            ->firstOrFail();

        try {
            $backupService->createFromPlan($run, $request->user('admin'));
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.backup_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.backup_created'));
    }

    private function ensureSuperAdmin(): void
    {
        $admin = request()->user('admin');
        if (! $admin || ! method_exists($admin, 'isSuperAdmin') || ! $admin->isSuperAdmin()) {
            abort(403, 'Forbidden');
        }
    }

    private function ensureUpdateCenterEnabled(): void
    {
        abort_unless((bool) config('geoflow.update_center_enabled', true), 404);
    }
}
