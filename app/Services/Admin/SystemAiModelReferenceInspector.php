<?php

namespace App\Services\Admin;

use App\Models\AiModel;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SystemAiModelReferenceInspector
{
    public function __construct(
        private readonly StructuredAiModelReferenceInspector $structuredReferenceInspector,
    ) {}

    /**
     * @return array{
     *   system_model_ids:list<int>,
     *   system_only_model_ids:list<int>,
     *   conflict_model_ids:list<int>,
     *   invalid_bindings:list<array{setting_key:string,reason:string}>,
     *   historical_structured_reference_count:int,
     *   structured_reference_finding_count:int,
     *   active_blocking_structured_reference_finding_count:int,
     *   structured_reference_findings:list<array{reference:string,row_id:int,path:string,state:string,reason:string}>
     * }
     */
    public function inspect(int $legacyOwnerId, bool $lockForUpdate = false): array
    {
        [$systemModelIds, $validBindings, $invalidBindings] = $this->systemModelBindings($lockForUpdate);
        $modelsQuery = AiModel::query()
            ->whereIn('id', $systemModelIds)
            ->orderBy('id');
        if ($lockForUpdate) {
            $modelsQuery->lockForUpdate();
        }
        $models = $modelsQuery
            ->get(['id', 'owner_admin_id'])
            ->keyBy('id');
        $existingIds = $models->keys()->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($validBindings as $binding) {
            if (! in_array($binding['model_id'], $existingIds, true)) {
                $invalidBindings[] = [
                    'setting_key' => $binding['setting_key'],
                    'reason' => 'model_not_found',
                ];
            }
        }

        $userContentIds = $this->userContentModelIds($existingIds, $lockForUpdate);
        $structuredReferences = $this->structuredReferenceInspector->inspect($existingIds, $lockForUpdate);
        $userContentIds = array_values(array_unique([
            ...$userContentIds,
            ...$structuredReferences['active_model_ids'],
        ]));
        $conflicts = [];
        $systemOnly = [];
        foreach ($existingIds as $modelId) {
            $model = $models->get($modelId);
            $hasForeignOwner = $model?->owner_admin_id !== null
                && (int) $model->owner_admin_id !== $legacyOwnerId;
            if ($hasForeignOwner || in_array($modelId, $userContentIds, true)) {
                $conflicts[] = $modelId;
            } else {
                $systemOnly[] = $modelId;
            }
        }

        sort($systemModelIds);
        sort($systemOnly);
        sort($conflicts);
        usort($invalidBindings, static fn (array $left, array $right): int => [
            $left['setting_key'],
            $left['reason'],
        ] <=> [
            $right['setting_key'],
            $right['reason'],
        ]);

        return [
            'system_model_ids' => array_values(array_unique($systemModelIds)),
            'system_only_model_ids' => array_values(array_unique($systemOnly)),
            'conflict_model_ids' => array_values(array_unique($conflicts)),
            'invalid_bindings' => $invalidBindings,
            'historical_structured_reference_count' => $structuredReferences['historical_reference_count'],
            'structured_reference_finding_count' => $structuredReferences['finding_count'],
            'active_blocking_structured_reference_finding_count' => $structuredReferences['active_blocking_finding_count'],
            'structured_reference_findings' => $structuredReferences['findings'],
        ];
    }

    /**
     * @return array{
     *   list<int>,
     *   list<array{setting_key:string,model_id:int}>,
     *   list<array{setting_key:string,reason:string}>
     * }
     */
    private function systemModelBindings(bool $lockForUpdate): array
    {
        $ids = [];
        $valid = [];
        $invalid = [];
        $settings = SiteSetting::query()
            ->whereIn('setting_key', AiModelReferenceCatalog::SYSTEM_SETTING_KEYS)
            ->orderBy('setting_key');
        if ($lockForUpdate) {
            $settings->lockForUpdate();
        }
        $settings = $settings->get(['setting_key', 'setting_value']);

        foreach ($settings as $setting) {
            $value = trim((string) $setting->setting_value);
            $modelId = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
            ]);
            if ($modelId === false || (string) $modelId !== $value) {
                $invalid[] = [
                    'setting_key' => (string) $setting->setting_key,
                    'reason' => 'invalid_model_id',
                ];

                continue;
            }

            $ids[] = $modelId;
            $valid[] = [
                'setting_key' => (string) $setting->setting_key,
                'model_id' => $modelId,
            ];
        }

        return [array_values(array_unique($ids)), $valid, $invalid];
    }

    /** @param list<int> $candidateIds
     * @return list<int>
     */
    private function userContentModelIds(array $candidateIds, bool $lockForUpdate): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $ids = [];
        foreach (AiModelReferenceCatalog::USER_CONTENT_REFERENCES as $modelClass => $columns) {
            /** @var Model $model */
            $model = new $modelClass;
            $table = $model->getTable();
            if (! Schema::hasTable($table)) {
                continue;
            }

            $availableColumns = array_values(array_filter(
                $columns,
                static fn (string $column): bool => Schema::hasColumn($table, $column),
            ));
            if ($availableColumns === []) {
                continue;
            }

            $query = DB::table($table)
                ->where(function ($query) use ($availableColumns, $candidateIds): void {
                    foreach ($availableColumns as $index => $column) {
                        $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                        $query->{$method}($column, $candidateIds);
                    }
                });
            if ($modelClass === KnowledgeFactGenerationRun::class
                && Schema::hasColumns($table, ['status', 'retryable_failure'])) {
                $query->where(function ($state): void {
                    $state->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
                        ->orWhere(function ($retryable): void {
                            $retryable->whereIn('status', [
                                KnowledgeFactGenerationRun::STATUS_FAILED,
                                'partial',
                            ])->where('retryable_failure', true);
                        })
                        ->orWhere(function ($unknown): void {
                            $knownStatuses = [
                                ...KnowledgeFactGenerationRun::ACTIVE_STATUSES,
                                KnowledgeFactGenerationRun::STATUS_COMPLETED,
                                KnowledgeFactGenerationRun::STATUS_FAILED,
                                KnowledgeFactGenerationRun::STATUS_CANCELLED,
                                KnowledgeFactGenerationRun::STATUS_OBSOLETE,
                                'partial',
                            ];
                            $unknown->whereNull('status')
                                ->orWhereNotIn('status', $knownStatuses);
                        });
                });
            }
            $query->orderBy($model->getKeyName());
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            foreach ($query->get($availableColumns) as $row) {
                foreach ($availableColumns as $column) {
                    $id = $row->{$column};
                    if ($id !== null) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }
}
