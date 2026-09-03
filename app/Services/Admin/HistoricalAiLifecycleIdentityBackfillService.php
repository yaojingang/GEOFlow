<?php

namespace App\Services\Admin;

use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\AiWorkspaceRun;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\TitleGenerationRun;
use App\Models\UrlImportJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class HistoricalAiLifecycleIdentityBackfillService
{
    private const UNRESOLVED_ERROR_CODE = 'ai_historical_identity_unresolved';

    /** @return array<string, mixed> */
    public function buildPlan(Admin $legacyOwner, CarbonImmutable $createdBefore): array
    {
        $updates = [];
        $fromCreators = 0;
        $fromLegacyOwner = 0;
        $frozen = 0;

        foreach ($this->specifications() as $specification) {
            $records = $this->historicalRecords($specification['model'], $createdBefore)->get();
            foreach ($records as $record) {
                if ($this->identityIsComplete($record)) {
                    continue;
                }

                $creator = $this->creatorFor($record, $specification);
                $mappedToLegacyOwner = ! $creator instanceof Admin;
                $executionAdmin = $creator ?? $legacyOwner;
                $attributes = [
                    'model_access_admin_id' => (int) $executionAdmin->getKey(),
                    'model_access_admin_role' => $executionAdmin->isSuperAdmin() ? 'super_admin' : 'admin',
                    'ai_config_access_version' => max(1, (int) $executionAdmin->ai_config_access_version),
                    'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
                ];
                $modelField = $specification['model_field'];
                if ($record->requested_ai_model_id === null && $modelField !== null && $record->{$modelField} !== null) {
                    $attributes['requested_ai_model_id'] = (int) $record->{$modelField};
                }

                if (($mappedToLegacyOwner || (string) $executionAdmin->status !== 'active')
                    && $this->isActiveOrRetryable($record, $specification['kind'])) {
                    $attributes = [...$attributes, ...$this->freezeAttributes($specification['kind'])];
                    $frozen++;
                }

                $updates[] = [
                    'model' => $specification['model'],
                    'id' => $record->getKey(),
                    'attributes' => $attributes,
                ];
                $mappedToLegacyOwner ? $fromLegacyOwner++ : $fromCreators++;
            }
        }

        return [
            'lifecycle_identities_recovered_from_creators' => $fromCreators,
            'lifecycle_identities_mapped_to_legacy_owner' => $fromLegacyOwner,
            'unattributed_active_lifecycle_records_to_freeze' => $frozen,
            '_lifecycle_identity_updates' => $updates,
        ];
    }

    /** @param array<string, mixed> $plan @return array<string, int> */
    public function applyPlan(array $plan): array
    {
        $updated = 0;
        foreach ((array) ($plan['_lifecycle_identity_updates'] ?? []) as $change) {
            $modelClass = $change['model'] ?? null;
            if (! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }
            $record = $modelClass::query()->whereKey($change['id'])->first();
            if (! $record instanceof Model || $this->identityIsComplete($record)) {
                continue;
            }
            $record->forceFill((array) $change['attributes'])->save();
            $updated++;
        }

        return ['lifecycle_identity_records_updated' => $updated];
    }

    /** @return list<array{model:class-string<Model>,kind:string,creator_field:string,creator_type:string,model_field:?string}> */
    private function specifications(): array
    {
        return [
            ['model' => UrlImportJob::class, 'kind' => 'url_import', 'creator_field' => 'created_by', 'creator_type' => 'username', 'model_field' => null],
            ['model' => EnterpriseKnowledgeProject::class, 'kind' => 'enterprise_knowledge', 'creator_field' => 'created_by_admin_id', 'creator_type' => 'id', 'model_field' => 'ai_model_id'],
            ['model' => TitleGenerationRun::class, 'kind' => 'title_generation', 'creator_field' => 'created_by_admin_id', 'creator_type' => 'id', 'model_field' => 'ai_model_id'],
            ['model' => AiWorkspaceRun::class, 'kind' => 'ai_workspace', 'creator_field' => 'admin_id', 'creator_type' => 'id', 'model_field' => null],
            ['model' => KnowledgeFactGenerationRun::class, 'kind' => 'knowledge_fact', 'creator_field' => 'created_by_admin_id', 'creator_type' => 'id', 'model_field' => 'ai_model_id'],
        ];
    }

    /** @param class-string<Model> $modelClass */
    private function historicalRecords(string $modelClass, CarbonImmutable $createdBefore): Builder
    {
        return $modelClass::query()
            ->where(function (Builder $query) use ($createdBefore): void {
                $query->whereNull('created_at')->orWhere('created_at', '<', $createdBefore);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('model_access_admin_id')
                    ->orWhereNull('model_access_admin_role')
                    ->orWhereNull('ai_config_access_version')
                    ->orWhereNull('resolver_policy_version');
            })
            ->orderBy((new $modelClass)->getKeyName());
    }

    /** @param array{creator_field:string,creator_type:string} $specification */
    private function creatorFor(Model $record, array $specification): ?Admin
    {
        $value = $record->{$specification['creator_field']};
        if ($specification['creator_type'] === 'username') {
            $username = trim((string) $value);

            return $username === '' ? null : Admin::query()->where('username', $username)->first();
        }

        $adminId = (int) ($value ?? 0);

        return $adminId > 0 ? Admin::query()->whereKey($adminId)->first() : null;
    }

    private function identityIsComplete(Model $record): bool
    {
        return (int) ($record->model_access_admin_id ?? 0) > 0
            && in_array((string) ($record->model_access_admin_role ?? ''), ['admin', 'super_admin'], true)
            && (int) ($record->ai_config_access_version ?? 0) > 0
            && (int) ($record->resolver_policy_version ?? 0) === AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION;
    }

    private function isActiveOrRetryable(Model $record, string $kind): bool
    {
        return match ($kind) {
            'url_import' => in_array((string) $record->status, ['queued', 'running'], true)
                || ((string) $record->status === 'failed' && (bool) $record->retryable_failure),
            'enterprise_knowledge' => in_array((string) $record->status, ['queued', 'processing'], true)
                || ((string) $record->status === 'failed' && (bool) $record->retryable_failure),
            'title_generation' => $record->isActive() || $record->isRetryable(),
            'ai_workspace' => ! $record->isTerminal()
                || (in_array((string) $record->state, ['failed', 'partially_completed'], true)
                    && (bool) $record->retryable_failure),
            'knowledge_fact' => $record->isActive()
                || ((string) $record->status === KnowledgeFactGenerationRun::STATUS_FAILED
                    && (bool) $record->retryable_failure),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function freezeAttributes(string $kind): array
    {
        $common = ['retryable_failure' => false];

        return match ($kind) {
            'url_import' => [...$common, 'status' => 'failed', 'error_code' => self::UNRESOLVED_ERROR_CODE, 'error_message' => self::UNRESOLVED_ERROR_CODE, 'execution_lease_token' => null, 'lease_expires_at' => null, 'finished_at' => now()],
            'enterprise_knowledge' => [...$common, 'status' => 'failed', 'error_code' => self::UNRESOLVED_ERROR_CODE, 'error_message' => self::UNRESOLVED_ERROR_CODE, 'execution_lease_token' => null, 'lease_expires_at' => null],
            'title_generation' => [...$common, 'status' => TitleGenerationRun::STATUS_FAILED, 'failure_code' => self::UNRESOLVED_ERROR_CODE, 'error_code' => self::UNRESOLVED_ERROR_CODE, 'last_error' => self::UNRESOLVED_ERROR_CODE, 'active_key' => null, 'dispatch_token' => null, 'lease_token' => null, 'lease_expires_at' => null, 'failed_at' => now()],
            'ai_workspace' => [...$common, 'state' => 'rejected', 'failure_code' => self::UNRESOLVED_ERROR_CODE, 'failure_message' => self::UNRESOLVED_ERROR_CODE, 'resolution_lease_owner' => null, 'resolution_lease_expires_at' => null, 'execution_lease_token' => null, 'execution_lease_expires_at' => null, 'finished_at' => now()],
            'knowledge_fact' => [...$common, 'status' => KnowledgeFactGenerationRun::STATUS_FAILED, 'error_code' => self::UNRESOLVED_ERROR_CODE, 'error_message' => self::UNRESOLVED_ERROR_CODE, 'active_key' => null, 'finalizer_lease_token' => null, 'finalizer_lease_expires_at' => null, 'failed_at' => now()],
            default => [],
        };
    }
}
