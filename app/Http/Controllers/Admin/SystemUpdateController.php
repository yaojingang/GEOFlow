<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSystemUpdateApplyJob;
use App\Jobs\ProcessSystemUpdateRollbackJob;
use App\Models\Admin;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\SystemUpdateApplyService;
use App\Services\Admin\SystemUpdateBackupInspectionService;
use App\Services\Admin\SystemUpdateBackupService;
use App\Services\Admin\SystemUpdateOperationGuard;
use App\Services\Admin\SystemUpdatePlanService;
use App\Services\Admin\SystemUpdateRollbackService;
use App\Services\Admin\SystemUpdateRunHealthService;
use App\Services\Admin\SystemUpdateStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

    public function runsStatus(SystemUpdateStateService $stateService): JsonResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        $summary = $stateService->summary();

        return response()->json([
            'html' => view('admin.system-updates.partials.recent-runs', [
                'recentRuns' => $summary['recent_runs'] ?? collect(),
            ])->render(),
            'has_active_run' => (bool) ($summary['has_active_run'] ?? false),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function runShow(string $runUuid, SystemUpdateRunHealthService $runHealthService): View
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        $run = SystemUpdateRun::query()
            ->with(['startedBy', 'backups'])
            ->where('run_uuid', $runUuid)
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->firstOrFail();

        return view('admin.system-updates.run-show', [
            'pageTitle' => __('admin.system_updates.section.run_detail'),
            'activeMenu' => 'dashboard',
            'run' => $run,
            'isStale' => $runHealthService->isStale($run),
            'canRetry' => $runHealthService->canRetry($run),
            'canMarkFailed' => $runHealthService->canMarkFailed($run),
            'passwordRequired' => (bool) config('geoflow.update_require_admin_password', true),
        ]);
    }

    public function plan(SystemUpdatePlanService $planService, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        try {
            $operationGuard->run(function () use ($operationGuard, $planService): void {
                $operationGuard->assertNoActiveExecution();
                $planService->createPlan(request()->user('admin'));
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.plan_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.plan_created'));
    }

    public function backup(Request $request, SystemUpdateBackupService $backupService, SystemUpdateOperationGuard $operationGuard): RedirectResponse
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
            $operationGuard->run(function () use ($operationGuard, $backupService, $run, $request): void {
                $operationGuard->assertNoActiveExecution();
                $backupService->createFromPlan($run, $request->user('admin'));
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.backup_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.backup_created'));
    }

    public function markCommandExecuted(Request $request, string $runUuid, int $commandIndex): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        $run = SystemUpdateRun::query()
            ->where('run_uuid', $runUuid)
            ->where('action', 'plan')
            ->where('status', 'succeeded')
            ->firstOrFail();

        $plan = is_array($run->plan_json) ? $run->plan_json : [];
        $commands = is_array($plan['manual_commands'] ?? null) ? $plan['manual_commands'] : [];
        if (! array_key_exists($commandIndex, $commands)) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.error.command_not_found')]);
        }

        $admin = $request->user('admin');
        $statuses = is_array($plan['manual_command_statuses'] ?? null) ? $plan['manual_command_statuses'] : [];
        $statuses[(string) $commandIndex] = [
            'executed_at' => now()->toDateTimeString(),
            'admin_id' => (int) $admin->id,
            'admin_name' => (string) ($admin->display_name ?: $admin->username),
        ];

        $plan['manual_command_statuses'] = $statuses;
        $run->forceFill(['plan_json' => $plan])->save();

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.command_marked'));
    }

    public function apply(Request $request, SystemUpdateApplyService $applyService, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();
        $this->validateAdminPassword($request);

        $validated = $request->validate([
            'run_uuid' => ['required', 'string', 'max:64'],
        ]);

        $run = SystemUpdateRun::query()
            ->where('run_uuid', (string) $validated['run_uuid'])
            ->where('action', 'plan')
            ->where('status', 'succeeded')
            ->firstOrFail();

        try {
            $queuedRun = $operationGuard->run(function () use ($operationGuard, $applyService, $run, $request): SystemUpdateRun {
                $operationGuard->assertNoActiveExecution();

                return $applyService->queueApply($run, $request->user('admin'));
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.apply_failed', ['message' => $e->getMessage()])]);
        }

        try {
            $this->dispatchQueuedRun($queuedRun);
        } catch (\Throwable $e) {
            $this->markDispatchFailed($queuedRun, $e);

            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.apply_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.apply_created'));
    }

    public function backupShow(string $backupUuid, SystemUpdateBackupInspectionService $inspectionService): View
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();

        $backup = SystemUpdateBackup::query()
            ->with(['createdBy', 'run'])
            ->where('backup_uuid', $backupUuid)
            ->firstOrFail();
        $inspection = $inspectionService->inspect($backup);

        return view('admin.system-updates.backup-show', [
            'pageTitle' => __('admin.system_updates.section.backup_detail'),
            'activeMenu' => 'dashboard',
            'backup' => $backup,
            'manifest' => $inspection['manifest'],
            'files' => $inspection['files'],
            'preflight' => $inspection['preflight'],
            'rollbackReady' => (bool) config('geoflow.update_execution_enabled', false)
                && (bool) config('geoflow.update_rollback_enabled', false),
            'passwordRequired' => (bool) config('geoflow.update_require_admin_password', true),
        ]);
    }

    public function rollback(Request $request, string $backupUuid, SystemUpdateRollbackService $rollbackService, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();
        $this->validateAdminPassword($request);

        $backup = SystemUpdateBackup::query()
            ->where('backup_uuid', $backupUuid)
            ->firstOrFail();

        try {
            $queuedRun = $operationGuard->run(function () use ($operationGuard, $rollbackService, $backup, $request): SystemUpdateRun {
                $operationGuard->assertNoActiveExecution();

                return $rollbackService->queueRollback($backup, $request->user('admin'));
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.rollback_failed', ['message' => $e->getMessage()])]);
        }

        try {
            $this->dispatchQueuedRun($queuedRun);
        } catch (\Throwable $e) {
            $this->markDispatchFailed($queuedRun, $e);

            return redirect()
                ->route('admin.system-updates.index')
                ->withErrors([__('admin.system_updates.message.rollback_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.index')
            ->with('message', __('admin.system_updates.message.rollback_created'));
    }

    public function rollbackFile(Request $request, string $backupUuid, SystemUpdateRollbackService $rollbackService, SystemUpdateOperationGuard $operationGuard): RedirectResponse
    {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();
        $this->validateAdminPassword($request);

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:500'],
        ]);

        $backup = SystemUpdateBackup::query()
            ->where('backup_uuid', $backupUuid)
            ->firstOrFail();

        try {
            $queuedRun = $operationGuard->run(function () use ($operationGuard, $rollbackService, $backup, $validated, $request): SystemUpdateRun {
                $operationGuard->assertNoActiveExecution();

                return $rollbackService->queueRollbackFile($backup, (string) $validated['path'], $request->user('admin'));
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.backups.show', ['backupUuid' => $backupUuid])
                ->withErrors([__('admin.system_updates.message.rollback_failed', ['message' => $e->getMessage()])]);
        }

        try {
            $this->dispatchQueuedRun($queuedRun);
        } catch (\Throwable $e) {
            $this->markDispatchFailed($queuedRun, $e);

            return redirect()
                ->route('admin.system-updates.backups.show', ['backupUuid' => $backupUuid])
                ->withErrors([__('admin.system_updates.message.rollback_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.backups.show', ['backupUuid' => $backupUuid])
            ->with('message', __('admin.system_updates.message.rollback_file_created'));
    }

    public function retryRun(
        Request $request,
        string $runUuid,
        SystemUpdateApplyService $applyService,
        SystemUpdateRollbackService $rollbackService,
        SystemUpdateRunHealthService $runHealthService,
        SystemUpdateOperationGuard $operationGuard
    ): RedirectResponse {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();
        $this->validateAdminPassword($request);

        $run = SystemUpdateRun::query()
            ->where('run_uuid', $runUuid)
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->firstOrFail();

        try {
            $queuedRun = $operationGuard->run(function () use ($operationGuard, $runHealthService, $run, $request, $applyService, $rollbackService): SystemUpdateRun {
                $operationGuard->assertNoActiveExecution($run);

                return $this->queueRetryRun($run, $request->user('admin'), $applyService, $rollbackService, $runHealthService);
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.runs.show', ['runUuid' => $runUuid])
                ->withErrors([__('admin.system_updates.message.retry_failed', ['message' => $e->getMessage()])]);
        }

        try {
            $this->dispatchQueuedRun($queuedRun);
        } catch (\Throwable $e) {
            $this->markDispatchFailed($queuedRun, $e);

            return redirect()
                ->route('admin.system-updates.runs.show', ['runUuid' => $runUuid])
                ->withErrors([__('admin.system_updates.message.retry_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.runs.show', ['runUuid' => $queuedRun->run_uuid])
            ->with('message', __('admin.system_updates.message.retry_created'));
    }

    public function markRunFailed(
        Request $request,
        string $runUuid,
        SystemUpdateRunHealthService $runHealthService,
        SystemUpdateOperationGuard $operationGuard
    ): RedirectResponse {
        $this->ensureUpdateCenterEnabled();
        $this->ensureSuperAdmin();
        $this->validateAdminPassword($request);

        $run = SystemUpdateRun::query()
            ->where('run_uuid', $runUuid)
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->firstOrFail();

        try {
            $operationGuard->run(fn () => $runHealthService->markStaleRunFailed($run, $request->user('admin')));
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.system-updates.runs.show', ['runUuid' => $runUuid])
                ->withErrors([__('admin.system_updates.message.mark_failed_failed', ['message' => $e->getMessage()])]);
        }

        return redirect()
            ->route('admin.system-updates.runs.show', ['runUuid' => $runUuid])
            ->with('message', __('admin.system_updates.message.run_marked_failed'));
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

    private function validateAdminPassword(Request $request): void
    {
        if (! (bool) config('geoflow.update_require_admin_password', true)) {
            return;
        }

        $validated = $request->validate([
            'current_admin_password' => ['required', 'string'],
        ]);

        $admin = $request->user('admin');
        if (! $admin || ! Hash::check((string) $validated['current_admin_password'], (string) $admin->password)) {
            throw ValidationException::withMessages([
                'current_admin_password' => __('admin.system_updates.error.admin_password_invalid'),
            ]);
        }
    }

    private function dispatchQueuedRun(SystemUpdateRun $run): void
    {
        if ((string) $run->action === 'apply') {
            ProcessSystemUpdateApplyJob::dispatch((int) $run->id)->onQueue('system-updates');

            return;
        }

        if (in_array((string) $run->action, ['rollback', 'rollback_file'], true)) {
            ProcessSystemUpdateRollbackJob::dispatch((int) $run->id)->onQueue('system-updates');

            return;
        }

        throw new \RuntimeException(__('admin.system_updates.error.run_not_executable'));
    }

    private function queueRetryRun(
        SystemUpdateRun $run,
        Admin $admin,
        SystemUpdateApplyService $applyService,
        SystemUpdateRollbackService $rollbackService,
        SystemUpdateRunHealthService $runHealthService
    ): SystemUpdateRun {
        if (! $runHealthService->canRetry($run)) {
            throw new \RuntimeException(__('admin.system_updates.error.run_not_retryable'));
        }

        if ($runHealthService->canMarkFailed($run)) {
            $runHealthService->markStaleRunFailed($run, $admin);
        }

        $payload = is_array($run->plan_json) ? $run->plan_json : [];

        if ((string) $run->action === 'apply') {
            $planRun = $this->resolveSourcePlanRun($payload);
            $queuedRun = $applyService->queueApply($planRun, $admin);
        } else {
            $backupUuid = trim((string) ($payload['backup_uuid'] ?? ''));
            if ($backupUuid === '') {
                throw new \RuntimeException(__('admin.system_updates.error.run_retry_source_missing'));
            }

            $backup = SystemUpdateBackup::query()->where('backup_uuid', $backupUuid)->first();
            if (! $backup) {
                throw new \RuntimeException(__('admin.system_updates.error.backup_manifest_missing'));
            }

            $queuedRun = (string) $run->action === 'rollback_file'
                ? $rollbackService->queueRollbackFile($backup, (string) ($payload['file_path'] ?? ''), $admin)
                : $rollbackService->queueRollback($backup, $admin);
        }

        $queuedPayload = is_array($queuedRun->plan_json) ? $queuedRun->plan_json : [];
        $queuedPayload['retried_from_run_uuid'] = (string) $run->run_uuid;
        $queuedRun->forceFill(['plan_json' => $queuedPayload])->save();

        return $queuedRun;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSourcePlanRun(array $payload): SystemUpdateRun
    {
        $sourcePlanRunId = (int) ($payload['source_plan_run_id'] ?? 0);
        $sourcePlanRunUuid = trim((string) ($payload['source_plan_run_uuid'] ?? ''));

        $query = SystemUpdateRun::query()
            ->where('action', 'plan')
            ->where('status', 'succeeded');

        if ($sourcePlanRunId > 0) {
            $planRun = (clone $query)->whereKey($sourcePlanRunId)->first();
            if ($planRun) {
                return $planRun;
            }
        }

        if ($sourcePlanRunUuid !== '') {
            $planRun = (clone $query)->where('run_uuid', $sourcePlanRunUuid)->first();
            if ($planRun) {
                return $planRun;
            }
        }

        throw new \RuntimeException(__('admin.system_updates.error.run_retry_source_missing'));
    }

    private function markDispatchFailed(SystemUpdateRun $run, \Throwable $e): void
    {
        $payload = is_array($run->plan_json) ? $run->plan_json : [];
        $payload['progress'] = array_merge(is_array($payload['progress'] ?? null) ? $payload['progress'] : [], [[
            'key' => 'failed',
            'percent' => 100,
            'status' => 'failed',
            'at' => now()->toDateTimeString(),
        ]]);
        $payload['progress_percent'] = 100;
        $payload['progress_status'] = 'failed';

        $run->forceFill([
            'status' => 'failed',
            'plan_json' => $payload,
            'error_message' => $e->getMessage(),
            'finished_at' => now(),
        ])->save();
    }
}
