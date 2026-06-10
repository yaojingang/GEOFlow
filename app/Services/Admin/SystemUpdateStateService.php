<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use Illuminate\Support\Facades\Schema;

class SystemUpdateStateService
{
    public function __construct(
        private readonly AdminUpdateMetadataService $metadataService,
        private readonly SystemUpdateDeploymentDetector $deploymentDetector,
        private readonly SystemUpdateDeploymentDiagnosticsService $deploymentDiagnosticsService,
        private readonly SystemUpdatePreflightService $preflightService,
        private readonly SystemUpdateRunHealthService $runHealthService,
        private readonly SystemUpdateArchiveValidator $archiveValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $notification = $this->metadataService->buildNotificationPayload();
        $state = is_array($notification['state'] ?? null) ? $notification['state'] : [];
        $links = is_array($notification['links'] ?? null) ? $notification['links'] : [];
        $deployment = $this->deploymentDetector->detect();
        $latestPlan = $this->latestPlanForState($state);
        $hasActiveRun = $this->hasActiveRun();
        $planStatus = $this->planStatus($state, $hasActiveRun);

        return [
            'state' => $state,
            'links' => $links,
            'deployment' => $deployment,
            'deployment_diagnostics' => $this->deploymentDiagnosticsService->build($deployment),
            'latest_plan' => $latestPlan,
            'preflight' => $this->preflightService->build($state, $deployment, $latestPlan),
            'recent_backups' => $this->recentBackups(),
            'recent_runs' => $this->recentRuns(),
            'has_active_run' => $hasActiveRun,
            'queue_health' => $this->runHealthService->summary(),
            'can_plan' => (bool) ($planStatus['can_plan'] ?? false),
            'plan_status' => $planStatus,
            'can_backup' => $latestPlan !== null,
            'execution_enabled' => (bool) config('geoflow.update_execution_enabled', false),
            'archive_apply_enabled' => (bool) config('geoflow.update_archive_apply_enabled', false),
            'rollback_enabled' => (bool) config('geoflow.update_execution_enabled', false)
                && (bool) config('geoflow.update_rollback_enabled', false),
            'admin_password_required' => (bool) config('geoflow.update_require_admin_password', true),
            'backup_keep' => max(1, (int) config('geoflow.update_backup_keep', 10)),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{can_plan: bool, key: string, message: string}
     */
    private function planStatus(array $state, bool $hasActiveRun): array
    {
        if ($hasActiveRun) {
            return $this->planStatusResult(false, 'active_run');
        }

        if (! (bool) ($state['is_update_available'] ?? false)) {
            return $this->planStatusResult(false, 'no_update');
        }

        $archiveUrl = trim((string) ($state['archive_url'] ?? ''));
        if ($archiveUrl === '') {
            return $this->planStatusResult(false, 'archive_missing');
        }

        try {
            $this->archiveValidator->assertAllowedArchiveUrl($archiveUrl);
        } catch (\Throwable) {
            return $this->planStatusResult(false, 'archive_untrusted');
        }

        return $this->planStatusResult(true, 'ready');
    }

    /**
     * @return array{can_plan: bool, key: string, message: string}
     */
    private function planStatusResult(bool $canPlan, string $key): array
    {
        return [
            'can_plan' => $canPlan,
            'key' => $key,
            'message' => __('admin.system_updates.plan_status.'.$key),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function latestPlanForState(array $state): ?SystemUpdateRun
    {
        if (! Schema::hasTable('system_update_runs')) {
            return null;
        }

        $latestVersion = trim((string) ($state['latest_version'] ?? ''));
        $latestCommit = trim((string) ($state['latest_commit'] ?? ''));
        if ($latestVersion === '' && $latestCommit === '') {
            return null;
        }

        $query = SystemUpdateRun::query()
            ->where('action', 'plan')
            ->where('status', 'succeeded');

        if ($latestVersion !== '') {
            $query->where('target_version', $latestVersion);
        }

        if ($latestCommit !== '') {
            $query->where('target_commit', $latestCommit);
        }

        return $query->latest('id')->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, SystemUpdateBackup>
     */
    private function recentBackups()
    {
        if (! Schema::hasTable('system_update_backups')) {
            return collect();
        }

        return SystemUpdateBackup::query()
            ->with('createdBy')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, SystemUpdateRun>
     */
    private function recentRuns()
    {
        if (! Schema::hasTable('system_update_runs')) {
            return collect();
        }

        return SystemUpdateRun::query()
            ->with('startedBy')
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->latest('id')
            ->limit(5)
            ->get();
    }

    private function hasActiveRun(): bool
    {
        if (! Schema::hasTable('system_update_runs')) {
            return false;
        }

        return SystemUpdateRun::query()
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
