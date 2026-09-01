<?php

namespace App\Services\AiWorkspace;

use App\Data\AiWorkspace\AiWorkspaceModelProbeResult;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class AiWorkspaceModelProbePersistenceService
{
    public function __construct(private AiWorkspaceModelReadiness $readiness) {}

    public function persist(
        Admin $actorSnapshot,
        AiModel $modelSnapshot,
        AiWorkspaceModelProbeResult $result,
    ): void {
        $this->withRevalidatedProbe($actorSnapshot, $modelSnapshot, function (AiModel $lockedModel) use ($result): void {
            $lockedModel->forceFill($result->persistenceAttributes())->save();
        });
    }

    public function persistFailure(Admin $actorSnapshot, AiModel $modelSnapshot, string $failureCode): void
    {
        $this->withRevalidatedProbe($actorSnapshot, $modelSnapshot, function (AiModel $lockedModel) use ($failureCode): void {
            $lockedModel->forceFill([
                'ai_workspace_structured_output_status' => null,
                'ai_workspace_structured_output_verified_at' => null,
                'ai_workspace_readiness_status' => 'failed',
                'ai_workspace_readiness_profile' => null,
                'ai_workspace_readiness_checked_at' => now(),
                'ai_workspace_readiness_expires_at' => null,
                'ai_workspace_readiness_failure_code' => $failureCode,
            ])->save();
        });
    }

    /** @param  Closure(AiModel): void  $persist */
    private function withRevalidatedProbe(
        Admin $actorSnapshot,
        AiModel $modelSnapshot,
        Closure $persist,
    ): void {
        $expectedAccessVersion = (int) $actorSnapshot->ai_config_access_version;
        $expectedFingerprint = $this->readiness->configurationFingerprint($modelSnapshot);
        $expectedOwnerAdminId = $modelSnapshot->owner_admin_id === null
            ? null
            : (int) $modelSnapshot->owner_admin_id;
        $expectedAccessScope = (string) $modelSnapshot->access_scope;
        $expectedArchivedAt = $modelSnapshot->archived_at?->toISOString();

        DB::transaction(function () use (
            $actorSnapshot,
            $modelSnapshot,
            $persist,
            $expectedAccessVersion,
            $expectedFingerprint,
            $expectedOwnerAdminId,
            $expectedAccessScope,
            $expectedArchivedAt,
        ): void {
            $lockedActor = Admin::query()->whereKey($actorSnapshot->getKey())->lockForUpdate()->first();
            if (! $lockedActor instanceof Admin
                || (string) $lockedActor->status !== 'active'
                || ! $lockedActor->isSuperAdmin()
                || (int) $lockedActor->ai_config_access_version !== $expectedAccessVersion) {
                throw AiModelAccessException::configAccessRevoked($actorSnapshot);
            }

            $lockedModel = AiModel::query()->whereKey($modelSnapshot->getKey())->lockForUpdate()->first();
            if (! $lockedModel instanceof AiModel
                || ($lockedModel->owner_admin_id === null ? null : (int) $lockedModel->owner_admin_id) !== $expectedOwnerAdminId
                || (string) $lockedModel->access_scope !== $expectedAccessScope
                || $lockedModel->archived_at?->toISOString() !== $expectedArchivedAt
                || ! hash_equals($expectedFingerprint, $this->readiness->configurationFingerprint($lockedModel))) {
                throw AiModelAccessException::configAccessRevoked($lockedActor, $lockedModel);
            }

            $persist($lockedModel);
        }, 3);
    }
}
