<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SystemUpdateStateService
{
    private const RECENT_HISTORY_DAYS = 90;

    public function __construct(
        private readonly AdminUpdateMetadataService $metadataService,
        private readonly SystemUpdateManualCommandService $manualCommands,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $historyScope = 'recent'): array
    {
        $notification = $this->metadataService->buildNotificationPayload();
        $state = is_array($notification['state'] ?? null) ? $notification['state'] : [];
        $links = is_array($notification['links'] ?? null) ? $notification['links'] : [];
        $releaseNotice = is_array($notification['release_notice'] ?? null) ? $notification['release_notice'] : [];
        $historyScope = $historyScope === 'archived' ? 'archived' : 'recent';
        $cutoff = now()->subDays(self::RECENT_HISTORY_DAYS);

        return [
            'state' => $state,
            'links' => $links,
            'release_notice' => $releaseNotice,
            'recent_runs' => $this->runs($historyScope, $cutoff),
            'recent_backups' => $this->backups($historyScope, $cutoff),
            'history_scope' => $historyScope,
            'history_days' => self::RECENT_HISTORY_DAYS,
            'archived_run_count' => $this->archivedCount(SystemUpdateRun::class, $cutoff),
            'archived_backup_count' => $this->archivedCount(SystemUpdateBackup::class, $cutoff),
            'has_legacy_active_run' => $this->hasLegacyActiveRun(),
            'admin_password_required' => (bool) config('geoflow.update_require_admin_password', true),
            'manual_commands' => $this->manualCommands->manualCommands(),
        ];
    }

    /** @return Collection<int, SystemUpdateRun>|LengthAwarePaginator<int, SystemUpdateRun> */
    private function runs(string $scope, \DateTimeInterface $cutoff): Collection|LengthAwarePaginator
    {
        if (! Schema::hasTable('system_update_runs')) {
            return collect();
        }

        return SystemUpdateRun::query()
            ->with('startedBy')
            ->when(
                $scope === 'archived',
                fn ($query) => $query->where('created_at', '<', $cutoff),
                fn ($query) => $query->where('created_at', '>=', $cutoff),
            )
            ->latest('id')
            ->paginate(20, ['*'], 'runs_page')
            ->withQueryString();
    }

    /** @return Collection<int, SystemUpdateBackup>|LengthAwarePaginator<int, SystemUpdateBackup> */
    private function backups(string $scope, \DateTimeInterface $cutoff): Collection|LengthAwarePaginator
    {
        if (! Schema::hasTable('system_update_backups')) {
            return collect();
        }

        return SystemUpdateBackup::query()
            ->with('createdBy')
            ->when(
                $scope === 'archived',
                fn ($query) => $query->where('created_at', '<', $cutoff),
                fn ($query) => $query->where('created_at', '>=', $cutoff),
            )
            ->latest('id')
            ->paginate(20, ['*'], 'backups_page')
            ->withQueryString();
    }

    /** @param  class-string<SystemUpdateRun|SystemUpdateBackup>  $model */
    private function archivedCount(string $model, \DateTimeInterface $cutoff): int
    {
        if (! Schema::hasTable((new $model)->getTable())) {
            return 0;
        }

        return $model::query()->where('created_at', '<', $cutoff)->count();
    }

    private function hasLegacyActiveRun(): bool
    {
        return Schema::hasTable('system_update_runs')
            && SystemUpdateRun::query()
                ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
                ->whereIn('status', ['queued', 'running'])
                ->exists();
    }
}
