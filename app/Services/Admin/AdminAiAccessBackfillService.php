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
    public function preview(?int $legacyOwnerId, CarbonImmutable $createdBefore): array
    {
        $owner = $this->resolveLegacyOwner($legacyOwnerId);

        return $this->buildPlan($owner, $createdBefore);
    }

    /** @return array<string, mixed> */
    public function apply(?int $legacyOwnerId, CarbonImmutable $createdBefore, int $batchSize): array
    {
        return DB::transaction(function () use ($legacyOwnerId, $createdBefore, $batchSize): array {
            $owner = $this->resolveLegacyOwner($legacyOwnerId, true);
            $plan = $this->buildPlan($owner, $createdBefore);

            $modelsAssigned = $this->historicalModels($createdBefore)
                ->whereNull('owner_admin_id')
                ->update(['owner_admin_id' => $owner->getKey(), 'updated_at' => now()]);
            $systemModelsMarked = $this->historicalModels($createdBefore)
                ->whereIn('id', $plan['system_only_model_ids'])
                ->where('access_scope', '!=', AiModel::ACCESS_SCOPE_SYSTEM_ONLY)
                ->update(['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY, 'updated_at' => now()]);

            $administratorsShared = 0;
            $this->historicalOrdinaryAdmins($createdBefore)
                ->select(['id', 'role', 'shared_ai_config_owner_id'])
                ->chunkById($batchSize, function ($admins) use ($owner, $createdBefore, &$administratorsShared): void {
                    foreach ($admins as $admin) {
                        if ($admin->isSuperAdmin() || $admin->shared_ai_config_owner_id !== null) {
                            continue;
                        }

                        $administratorsShared += Admin::query()
                            ->whereKey($admin->getKey())
                            ->whereNull('shared_ai_config_owner_id')
                            ->where('ai_config_access_version', '<=', 1)
                            ->where(function (Builder $query) use ($createdBefore): void {
                                $query
                                    ->whereNull('created_at')
                                    ->orWhere('created_at', '<=', $createdBefore);
                            })
                            ->whereRaw(
                                'LOWER(TRIM(role)) NOT IN (?, ?)',
                                ['super_admin', 'superadmin'],
                            )
                            ->update([
                                'shared_ai_config_owner_id' => $owner->getKey(),
                                'updated_at' => now(),
                            ]);
                    }
                });

            $versionsNormalized = Admin::query()
                ->where('ai_config_access_version', '<', 1)
                ->update(['ai_config_access_version' => 1, 'updated_at' => now()]);

            return [
                ...$plan,
                'models_assigned' => $modelsAssigned,
                'administrators_shared' => $administratorsShared,
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

        $query = Admin::query()->active();
        if ($lock) {
            $query->lockForUpdate();
        }
        $activeSuperAdmins = $query
            ->get(['id', 'role', 'status'])
            ->filter(static fn (Admin $admin): bool => $admin->isSuperAdmin())
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
    private function buildPlan(Admin $owner, CarbonImmutable $createdBefore): array
    {
        $references = $this->referenceInspector->inspect((int) $owner->getKey());

        return [
            'legacy_owner_id' => (int) $owner->getKey(),
            'created_before' => $createdBefore->toIso8601String(),
            'unowned_models' => $this->historicalModels($createdBefore)
                ->whereNull('owner_admin_id')
                ->count(),
            'historical_administrators' => $this->historicalOrdinaryAdmins($createdBefore)
                ->get(['id', 'role', 'shared_ai_config_owner_id'])
                ->reject(static fn (Admin $admin): bool => $admin->isSuperAdmin())
                ->count(),
            'invalid_access_versions' => Admin::query()->where('ai_config_access_version', '<', 1)->count(),
            'system_models_to_mark' => $this->historicalModels($createdBefore)
                ->whereIn('id', $references['system_only_model_ids'])
                ->where('access_scope', '!=', AiModel::ACCESS_SCOPE_SYSTEM_ONLY)
                ->count(),
            ...$references,
        ];
    }

    private function historicalOrdinaryAdmins(CarbonImmutable $createdBefore): Builder
    {
        return Admin::query()
            ->whereNull('shared_ai_config_owner_id')
            ->where('ai_config_access_version', '<=', 1)
            ->where(function (Builder $query) use ($createdBefore): void {
                $query
                    ->whereNull('created_at')
                    ->orWhere('created_at', '<=', $createdBefore);
            })
            ->orderBy('id');
    }

    private function historicalModels(CarbonImmutable $createdBefore): Builder
    {
        return AiModel::query()
            ->where(function (Builder $query) use ($createdBefore): void {
                $query
                    ->whereNull('created_at')
                    ->orWhere('created_at', '<=', $createdBefore);
            });
    }
}
