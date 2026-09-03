<?php

namespace App\Services\Admin;

use App\Models\AiModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class StructuredAiModelReferenceInspector
{
    private const SCAN_BATCH_SIZE = 200;

    private const MAX_REPORTED_FINDINGS = 100;

    private const BLOCKING_ACTIVE_FINDING_REASONS = [
        'invalid_json',
        'invalid_model_id',
        'model_not_found',
    ];

    public function __construct(
        private readonly StructuredAiModelReferenceParser $parser,
    ) {}

    /**
     * JSON is decoded and matched in PHP so SQLite and PostgreSQL share strict
     * positive-integer semantics. Only catalogued columns are read in chunks.
     *
     * @param  list<int>  $candidateIds
     * @return array{
     *   active_model_ids:list<int>,
     *   historical_reference_count:int,
     *   finding_count:int,
     *   active_blocking_finding_count:int,
     *   findings:list<array{reference:string,row_id:int,path:string,state:string,reason:string}>
     * }
     */
    public function inspect(array $candidateIds, bool $lockForUpdate): array
    {
        $activeIds = [];
        $historicalCount = 0;
        $findingCount = 0;
        $activeBlockingFindingCount = 0;
        $findings = [];

        foreach (AiModelReferenceCatalog::STRUCTURED_USER_CONTENT_REFERENCES as $definition) {
            /** @var Model $model */
            $model = new $definition['model'];
            $table = $model->getTable();
            $jsonColumn = $definition['json_column'];
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $jsonColumn)) {
                continue;
            }

            $statusColumn = $definition['status_column'] ?? null;
            if ($statusColumn !== null && ! Schema::hasColumn($table, $statusColumn)) {
                continue;
            }
            $activeBooleanColumn = $definition['active_boolean_column'] ?? null;
            if ($activeBooleanColumn !== null && ! Schema::hasColumn($table, $activeBooleanColumn)) {
                continue;
            }
            $retryableBooleanColumn = $definition['retryable_boolean_column'] ?? null;
            if ($retryableBooleanColumn !== null && ! Schema::hasColumn($table, $retryableBooleanColumn)) {
                continue;
            }
            if (isset($definition['active_parent_guard'])
                && ! $this->parentGuardIsAvailable($definition['active_parent_guard'])) {
                unset($definition['active_parent_guard']);
            }

            $activeQuery = $model->newQuery();
            $this->applyStateFilter($activeQuery, $definition, 'active');
            if ($lockForUpdate) {
                $activeQuery->lockForUpdate();
            }
            $this->scanQuery(
                $activeQuery,
                $model,
                $definition,
                'active',
                $candidateIds,
                $lockForUpdate,
                $activeIds,
                $historicalCount,
                $findingCount,
                $activeBlockingFindingCount,
                $findings,
            );

            if ($this->hasTerminalState($definition)) {
                $terminalQuery = $model->newQuery();
                $this->applyStateFilter($terminalQuery, $definition, 'historical');
                $this->scanQuery(
                    $terminalQuery,
                    $model,
                    $definition,
                    'historical',
                    $candidateIds,
                    false,
                    $activeIds,
                    $historicalCount,
                    $findingCount,
                    $activeBlockingFindingCount,
                    $findings,
                );
            }

            if ($statusColumn !== null) {
                $unknownQuery = $model->newQuery()
                    ->where(function (Builder $query) use ($statusColumn, $definition): void {
                        $knownStatuses = array_values(array_unique([
                            ...($definition['active_statuses'] ?? []),
                            ...($definition['terminal_statuses'] ?? []),
                            ...($definition['retryable_statuses'] ?? []),
                        ]));
                        $query->whereNull($statusColumn);
                        if ($knownStatuses !== []) {
                            $query->orWhereNotIn($statusColumn, $knownStatuses);
                        }
                    });
                if ($lockForUpdate) {
                    $unknownQuery->lockForUpdate();
                }
                $this->scanQuery(
                    $unknownQuery,
                    $model,
                    $definition,
                    'active',
                    $candidateIds,
                    $lockForUpdate,
                    $activeIds,
                    $historicalCount,
                    $findingCount,
                    $activeBlockingFindingCount,
                    $findings,
                    true,
                );
            }
        }

        sort($activeIds);
        usort($findings, static fn (array $left, array $right): int => [
            $left['reference'],
            $left['row_id'],
            $left['path'],
            $left['state'],
            $left['reason'],
        ] <=> [
            $right['reference'],
            $right['row_id'],
            $right['path'],
            $right['state'],
            $right['reason'],
        ]);

        return [
            'active_model_ids' => array_values(array_unique($activeIds)),
            'historical_reference_count' => $historicalCount,
            'finding_count' => $findingCount,
            'active_blocking_finding_count' => $activeBlockingFindingCount,
            'findings' => $findings,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function applyStateFilter(Builder $query, array $definition, string $state): void
    {
        $statusColumn = $definition['status_column'] ?? null;
        if ($statusColumn !== null) {
            $retryableStatuses = $definition['retryable_statuses'] ?? [];
            $retryableBooleanColumn = $definition['retryable_boolean_column'] ?? null;
            $query->where(function (Builder $stateQuery) use ($definition, $retryableStatuses, $retryableBooleanColumn, $state, $statusColumn): void {
                $statuses = $state === 'active'
                    ? ($definition['active_statuses'] ?? [])
                    : ($definition['terminal_statuses'] ?? []);
                $stateQuery->whereIn($statusColumn, $statuses);
                if ($retryableBooleanColumn === null || $retryableStatuses === []) {
                    return;
                }

                $stateQuery->orWhere(function (Builder $retryable) use ($retryableStatuses, $retryableBooleanColumn, $state, $statusColumn): void {
                    $retryable->whereIn($statusColumn, $retryableStatuses);
                    if ($state === 'active') {
                        $retryable->where($retryableBooleanColumn, true);
                    } else {
                        $retryable->where(function (Builder $notRetryable) use ($retryableBooleanColumn): void {
                            $notRetryable->whereNull($retryableBooleanColumn)
                                ->orWhere($retryableBooleanColumn, false);
                        });
                    }
                });
            });

            return;
        }

        $activeBooleanColumn = $definition['active_boolean_column'] ?? null;
        if ($activeBooleanColumn === null) {
            return;
        }
        $qualifiedActiveColumn = $query->getModel()->qualifyColumn($activeBooleanColumn);
        $parentGuard = $definition['active_parent_guard'] ?? null;
        if ($state === 'active') {
            $query->where($qualifiedActiveColumn, true);
            if (is_array($parentGuard)) {
                $this->applyActiveParentGuard($query, $parentGuard, false);
            }

            return;
        }

        $query->where(function (Builder $query) use ($qualifiedActiveColumn, $parentGuard): void {
            $query
                ->whereNull($qualifiedActiveColumn)
                ->orWhere($qualifiedActiveColumn, false);
            if (is_array($parentGuard)) {
                $query->orWhere(function (Builder $query) use ($qualifiedActiveColumn, $parentGuard): void {
                    $query->where($qualifiedActiveColumn, true);
                    $this->applyActiveParentGuard($query, $parentGuard, true);
                });
            }
        });
    }

    /** @param array<string, mixed> $guard */
    private function applyActiveParentGuard(Builder $query, array $guard, bool $exists): void
    {
        /** @var Model $parentModel */
        $parentModel = new $guard['model'];
        $parentTable = $parentModel->getTable();
        $childTable = $query->getModel()->getTable();
        $method = $exists ? 'whereExists' : 'whereNotExists';
        $query->{$method}(function ($parentQuery) use ($guard, $parentTable, $childTable): void {
            $parentQuery
                ->selectRaw('1')
                ->from($parentTable)
                ->whereColumn(
                    $parentTable.'.'.$guard['parent_key'],
                    $childTable.'.'.$guard['foreign_key'],
                );
            foreach ($guard['active_columns'] as $column => $value) {
                $parentQuery->where($parentTable.'.'.$column, $value);
            }
            foreach ($guard['active_null_columns'] as $column) {
                $parentQuery->whereNull($parentTable.'.'.$column);
            }
        });
    }

    /** @param array<string, mixed> $definition */
    private function hasTerminalState(array $definition): bool
    {
        return ($definition['terminal_statuses'] ?? []) !== []
            || isset($definition['active_boolean_column']);
    }

    /** @param array<string, mixed> $guard */
    private function parentGuardIsAvailable(array $guard): bool
    {
        /** @var Model $parentModel */
        $parentModel = new $guard['model'];
        $parentTable = $parentModel->getTable();
        $columns = [
            $guard['parent_key'],
            ...array_keys($guard['active_columns']),
            ...$guard['active_null_columns'],
        ];

        return Schema::hasTable($parentTable)
            && collect($columns)->every(
                static fn (string $column): bool => Schema::hasColumn($parentTable, $column),
            );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<int>  $candidateIds
     * @param  list<int>  $activeIds
     * @param  list<array{reference:string,row_id:int,path:string,state:string,reason:string}>  $findings
     */
    private function scanQuery(
        Builder $query,
        Model $model,
        array $definition,
        string $state,
        array $candidateIds,
        bool $lockReferencedModels,
        array &$activeIds,
        int &$historicalCount,
        int &$findingCount,
        int &$activeBlockingFindingCount,
        array &$findings,
        bool $unknownStatus = false,
    ): void {
        $keyName = $model->getKeyName();
        $jsonColumn = $definition['json_column'];
        $columns = [$keyName, $jsonColumn];
        if (isset($definition['status_column'])) {
            $columns[] = $definition['status_column'];
        }

        $query->select(array_values(array_unique($columns)))
            ->orderBy($keyName)
            ->chunkById(
                self::SCAN_BATCH_SIZE,
                function ($rows) use (
                    $model,
                    $definition,
                    $state,
                    $candidateIds,
                    $lockReferencedModels,
                    &$activeIds,
                    &$historicalCount,
                    &$findingCount,
                    &$activeBlockingFindingCount,
                    &$findings,
                    $unknownStatus,
                ): void {
                    $parsedRows = [];
                    $referencedIds = [];
                    foreach ($rows as $row) {
                        $rowId = (int) $row->getKey();
                        if ($unknownStatus) {
                            $this->recordFinding(
                                $findings,
                                $findingCount,
                                $model->getTable(),
                                $rowId,
                                $definition['json_column'],
                                $state,
                                'unclassified_status',
                                $activeBlockingFindingCount,
                            );
                        }

                        $parsed = $this->parser->parse(
                            $row->getRawOriginal($definition['json_column']),
                            $definition['json_column'],
                            $definition['paths'],
                        );
                        foreach ($parsed['findings'] as $finding) {
                            $this->recordFinding(
                                $findings,
                                $findingCount,
                                $model->getTable(),
                                $rowId,
                                $finding['path'],
                                $state,
                                $finding['reason'],
                                $activeBlockingFindingCount,
                            );
                        }
                        if ($parsed['references'] === []) {
                            continue;
                        }

                        $parsedRows[] = ['row_id' => $rowId, 'references' => $parsed['references']];
                        foreach ($parsed['references'] as $reference) {
                            $referencedIds = [...$referencedIds, ...$reference['model_ids']];
                        }
                    }

                    $referencedIds = array_values(array_unique($referencedIds));
                    $existingIds = [];
                    if ($referencedIds !== []) {
                        $existingQuery = AiModel::query()->whereIn('id', $referencedIds)->orderBy('id');
                        if ($lockReferencedModels) {
                            $existingQuery->lockForUpdate();
                        }
                        $existingIds = $existingQuery->pluck('id')
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->all();
                    }

                    foreach ($parsedRows as $parsedRow) {
                        foreach ($parsedRow['references'] as $reference) {
                            if (array_diff($reference['model_ids'], $existingIds) !== []) {
                                $this->recordFinding(
                                    $findings,
                                    $findingCount,
                                    $model->getTable(),
                                    $parsedRow['row_id'],
                                    $reference['path'],
                                    $state,
                                    'model_not_found',
                                    $activeBlockingFindingCount,
                                );
                            }

                            if ($state === 'historical') {
                                $historicalCount += count($reference['model_ids']);

                                continue;
                            }
                            foreach ($reference['model_ids'] as $modelId) {
                                if (in_array($modelId, $candidateIds, true)) {
                                    $activeIds[] = $modelId;
                                }
                            }
                        }
                    }
                },
                $keyName,
                $keyName,
            );
    }

    /**
     * @param  list<array{reference:string,row_id:int,path:string,state:string,reason:string}>  $findings
     */
    private function recordFinding(
        array &$findings,
        int &$findingCount,
        string $reference,
        int $rowId,
        string $path,
        string $state,
        string $reason,
        int &$activeBlockingFindingCount,
    ): void {
        $findingCount++;
        if ($state === 'active' && in_array($reason, self::BLOCKING_ACTIVE_FINDING_REASONS, true)) {
            $activeBlockingFindingCount++;
        }
        if (count($findings) >= self::MAX_REPORTED_FINDINGS) {
            return;
        }

        $findings[] = [
            'reference' => $reference,
            'row_id' => $rowId,
            'path' => $path,
            'state' => $state,
            'reason' => $reason,
        ];
    }
}
