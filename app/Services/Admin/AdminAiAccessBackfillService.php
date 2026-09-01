<?php

namespace App\Services\Admin;

use App\Exceptions\AdminAiAccessBackfillException;
use App\Models\Admin;
use App\Models\AiModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AdminAiAccessBackfillService
{
    public function __construct(private readonly SystemAiModelReferenceInspector $referenceInspector) {}

    /** @return array<string, mixed> */
    public function preview(
        ?int $legacyOwnerId,
        CarbonImmutable $createdBefore,
        ?int $adminMaxId,
        ?int $modelMaxId,
    ): array {
        $owner = $this->resolveLegacyOwner($legacyOwnerId);

        $this->assertSnapshotsCoverNullTimestamps($adminMaxId, $modelMaxId);

        return $this->buildPlan($owner, $createdBefore, $adminMaxId, $modelMaxId);
    }

    /** @return array<string, mixed> */
    public function apply(
        ?int $legacyOwnerId,
        CarbonImmutable $createdBefore,
        ?int $adminMaxId,
        ?int $modelMaxId,
        bool $maintenanceConfirmed,
        int $batchSize,
    ): array {
        return DB::transaction(function () use (
            $legacyOwnerId,
            $createdBefore,
            $adminMaxId,
            $modelMaxId,
            $maintenanceConfirmed,
            $batchSize,
        ): array {
            $this->assertMaintenanceGate($maintenanceConfirmed);
            $owner = $this->resolveLegacyOwner($legacyOwnerId, true);
            $this->assertSnapshotsCoverNullTimestamps($adminMaxId, $modelMaxId);
            $this->lockHistoricalCandidates($createdBefore, $adminMaxId, $modelMaxId);
            $plan = $this->buildPlan($owner, $createdBefore, $adminMaxId, $modelMaxId, true);

            $modelsAssigned = $this->historicalModels($createdBefore, $modelMaxId)
                ->whereNull('owner_admin_id')
                ->update(['owner_admin_id' => $owner->getKey(), 'updated_at' => now()]);
            $systemModelsMarked = $this->historicalModels($createdBefore, $modelMaxId)
                ->whereIn('id', $plan['system_only_model_ids'])
                ->where('access_scope', '!=', AiModel::ACCESS_SCOPE_SYSTEM_ONLY)
                ->update(['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY, 'updated_at' => now()]);

            $versionsNormalized = $this->historicalAdmins($createdBefore, $adminMaxId)
                ->where('ai_config_access_version', '<', 1)
                ->update(['ai_config_access_version' => 1, 'updated_at' => now()]);

            $superBindingsCleared = 0;
            $this->historicalSuperAdminBindings($createdBefore, $adminMaxId)
                ->select(['id', 'role', 'shared_ai_config_owner_id', 'ai_config_access_version'])
                ->lockForUpdate()
                ->chunkById($batchSize, function ($admins) use (
                    $createdBefore,
                    $adminMaxId,
                    &$superBindingsCleared,
                ): void {
                    foreach ($admins as $admin) {
                        $version = max(1, (int) $admin->ai_config_access_version) + 1;
                        $query = Admin::query()
                            ->whereKey($admin->getKey())
                            ->whereNotNull('shared_ai_config_owner_id')
                            ->whereRaw(
                                'LOWER(TRIM(role)) IN (?, ?)',
                                ['super_admin', 'superadmin'],
                            );
                        $this->applyHistoricalBoundary($query, $createdBefore, $adminMaxId);
                        $superBindingsCleared += $query->update([
                            'shared_ai_config_owner_id' => null,
                            'ai_config_access_version' => $version,
                            'updated_at' => now(),
                        ]);
                    }
                });

            $administratorsShared = 0;
            $this->historicalOrdinaryAdmins($createdBefore, $adminMaxId)
                ->select(['id', 'role', 'shared_ai_config_owner_id'])
                ->chunkById($batchSize, function ($admins) use (
                    $owner,
                    $createdBefore,
                    $adminMaxId,
                    &$administratorsShared,
                ): void {
                    foreach ($admins as $admin) {
                        if ($admin->isSuperAdmin() || $admin->shared_ai_config_owner_id !== null) {
                            continue;
                        }

                        $query = Admin::query()
                            ->whereKey($admin->getKey())
                            ->whereNull('shared_ai_config_owner_id')
                            ->where('ai_config_access_version', '<=', 1)
                            ->whereRaw(
                                'LOWER(TRIM(role)) NOT IN (?, ?)',
                                ['super_admin', 'superadmin'],
                            );
                        $this->applyHistoricalBoundary($query, $createdBefore, $adminMaxId);
                        $administratorsShared += $query->update([
                            'shared_ai_config_owner_id' => $owner->getKey(),
                            'updated_at' => now(),
                        ]);
                    }
                });

            return [
                ...$plan,
                'models_assigned' => $modelsAssigned,
                'administrators_shared' => $administratorsShared,
                'super_admin_bindings_cleared' => $superBindingsCleared,
                'access_versions_normalized' => $versionsNormalized,
                'system_models_marked' => $systemModelsMarked,
            ];
        }, 3);
    }

    private function resolveLegacyOwner(?int $legacyOwnerId, bool $lock = false): Admin
    {
        if ($legacyOwnerId !== null) {
            $query = Admin::query()->whereKey($legacyOwnerId);
            if ($lock) {
                $query->lockForUpdate();
            }
            $owner = $query->first(['id', 'role', 'status']);
            if (! $owner instanceof Admin || (string) $owner->status !== 'active' || ! $owner->isSuperAdmin()) {
                throw new AdminAiAccessBackfillException('legacy_owner_not_active_super_admin');
            }

            return $owner;
        }

        $query = Admin::query()
            ->active()
            ->whereRaw(
                'LOWER(TRIM(role)) IN (?, ?)',
                ['super_admin', 'superadmin'],
            )
            ->orderBy('id')
            ->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }
        $activeSuperAdmins = $query
            ->get(['id', 'role', 'status'])
            ->values();

        if ($activeSuperAdmins->isEmpty()) {
            throw new AdminAiAccessBackfillException('no_active_super_admin');
        }
        if ($activeSuperAdmins->count() > 1) {
            throw new AdminAiAccessBackfillException('multiple_active_super_admins');
        }

        return $activeSuperAdmins->first();
    }

    /** @return array<string, mixed> */
    private function buildPlan(
        Admin $owner,
        CarbonImmutable $createdBefore,
        ?int $adminMaxId,
        ?int $modelMaxId,
        bool $lockReferences = false,
    ): array {
        $references = $this->referenceInspector->inspect(
            (int) $owner->getKey(),
            $lockReferences,
        );

        return [
            'legacy_owner_id' => (int) $owner->getKey(),
            'created_before' => $createdBefore->toIso8601String(),
            'admin_max_id' => $adminMaxId,
            'model_max_id' => $modelMaxId,
            'unowned_models' => $this->historicalModels($createdBefore, $modelMaxId)
                ->whereNull('owner_admin_id')
                ->count(),
            'historical_administrators' => $this->historicalOrdinaryAdmins($createdBefore, $adminMaxId)
                ->count(),
            'super_admin_bindings_to_clear' => $this->historicalSuperAdminBindings(
                $createdBefore,
                $adminMaxId,
            )->count(),
            'invalid_access_versions' => $this->historicalAdmins($createdBefore, $adminMaxId)
                ->where('ai_config_access_version', '<', 1)
                ->count(),
            'system_models_to_mark' => $this->historicalModels($createdBefore, $modelMaxId)
                ->whereIn('id', $references['system_only_model_ids'])
                ->where('access_scope', '!=', AiModel::ACCESS_SCOPE_SYSTEM_ONLY)
                ->count(),
            ...$references,
        ];
    }

    private function historicalOrdinaryAdmins(
        CarbonImmutable $createdBefore,
        ?int $adminMaxId,
    ): Builder {
        return $this->historicalAdmins($createdBefore, $adminMaxId)
            ->whereNull('shared_ai_config_owner_id')
            ->where('ai_config_access_version', '<=', 1)
            ->whereRaw(
                'LOWER(TRIM(role)) NOT IN (?, ?)',
                ['super_admin', 'superadmin'],
            )
            ->orderBy('id');
    }

    private function historicalSuperAdminBindings(
        CarbonImmutable $createdBefore,
        ?int $adminMaxId,
    ): Builder {
        return $this->historicalAdmins($createdBefore, $adminMaxId)
            ->whereNotNull('shared_ai_config_owner_id')
            ->whereRaw(
                'LOWER(TRIM(role)) IN (?, ?)',
                ['super_admin', 'superadmin'],
            )
            ->orderBy('id');
    }

    private function historicalAdmins(CarbonImmutable $createdBefore, ?int $adminMaxId): Builder
    {
        $query = Admin::query();
        $this->applyHistoricalBoundary($query, $createdBefore, $adminMaxId);

        return $query;
    }

    private function historicalModels(CarbonImmutable $createdBefore, ?int $modelMaxId): Builder
    {
        $query = AiModel::query();
        $this->applyHistoricalBoundary($query, $createdBefore, $modelMaxId);

        return $query;
    }

    private function applyHistoricalBoundary(
        Builder $query,
        CarbonImmutable $createdBefore,
        ?int $maxId,
    ): void {
        $storageCutoff = $createdBefore->format('Y-m-d H:i:s');
        if ($maxId === null) {
            $query
                ->whereNotNull('created_at')
                ->where('created_at', '<=', $storageCutoff);

            return;
        }

        $query
            ->where('id', '<=', $maxId)
            ->where(function (Builder $boundary) use ($storageCutoff): void {
                $boundary
                    ->whereNull('created_at')
                    ->orWhere('created_at', '<=', $storageCutoff);
            });
    }

    private function assertSnapshotsCoverNullTimestamps(?int $adminMaxId, ?int $modelMaxId): void
    {
        if ($adminMaxId === null && Admin::query()->whereNull('created_at')->exists()) {
            throw new AdminAiAccessBackfillException('admin_max_id_required_for_null_created_at');
        }
        if ($modelMaxId === null && AiModel::query()->whereNull('created_at')->exists()) {
            throw new AdminAiAccessBackfillException('model_max_id_required_for_null_created_at');
        }
    }

    private function assertMaintenanceGate(bool $maintenanceConfirmed): void
    {
        if (! $maintenanceConfirmed) {
            throw new AdminAiAccessBackfillException('maintenance_confirmation_required');
        }
        if (! app()->isDownForMaintenance()) {
            throw new AdminAiAccessBackfillException('application_maintenance_mode_required');
        }
    }

    private function lockHistoricalCandidates(
        CarbonImmutable $createdBefore,
        ?int $adminMaxId,
        ?int $modelMaxId,
    ): void {
        $this->historicalAdmins($createdBefore, $adminMaxId)
            ->select(['id'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $this->historicalModels($createdBefore, $modelMaxId)
            ->select(['id'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
