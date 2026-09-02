<?php

namespace App\Services\Admin;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AdminAiAccessBackfillException;
use App\Models\Admin;
use App\Models\AiQualityAuditEvent;
use App\Models\Task;
use App\Models\TaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class HistoricalTaskExecutionIdentityBackfillService
{
    private const UNRESOLVED_ERROR_CODE = 'ai_historical_identity_unresolved';

    /** @return array<string, mixed> */
    public function buildPlan(
        Admin $legacyOwner,
        CarbonImmutable $createdBefore,
        ?int $taskMaxId,
        ?int $taskRunMaxId,
    ): array {
        $tasks = $this->historicalTasks($createdBefore, $taskMaxId)
            ->get([
                'id',
                'status',
                'schedule_enabled',
                'ai_model_id',
                'model_access_admin_id',
                'model_access_admin_role',
                'model_access_policy_version',
            ]);
        $runs = $this->historicalTaskRuns($createdBefore, $taskRunMaxId)
            ->get([
                'id',
                'task_id',
                'status',
                'model_access_admin_id',
                'model_access_admin_role',
                'ai_config_access_version',
                'requested_ai_model_id',
                'resolver_policy_version',
            ]);
        $tasksById = $tasks->keyBy(static fn (Task $task): int => (int) $task->getKey());
        $runsByTask = $runs->groupBy(static fn (TaskRun $run): int => (int) $run->task_id);
        $creationAudits = $this->creationAuditAdminsByTask(
            $tasks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            $createdBefore,
        );
        $adminIds = $tasks->pluck('model_access_admin_id')
            ->merge($runs->pluck('model_access_admin_id'))
            ->merge($creationAudits->flatten())
            ->push((int) $legacyOwner->getKey())
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $admins = Admin::query()
            ->whereIn('id', $adminIds)
            ->get(['id', 'role', 'ai_config_access_version'])
            ->keyBy(static fn (Admin $admin): int => (int) $admin->getKey());

        $findings = [];
        $taskSnapshots = [];
        $taskUpdates = [];
        $tasksFromRuns = 0;
        $tasksFromAudit = 0;
        $tasksFromLegacy = 0;
        $pauseTaskIds = [];

        foreach ($tasks as $task) {
            $taskId = (int) $task->getKey();
            $taskRuns = $runsByTask->get($taskId, collect());
            $snapshot = $this->existingTaskSnapshot($task, $admins, $findings);
            if ($snapshot === null && $this->taskIdentityIsEmpty($task)) {
                $snapshot = $this->snapshotFromConsistentRuns($taskId, $taskRuns, $admins, $findings);
                if ($snapshot !== null) {
                    $snapshot['source'] = 'historical_runs';
                    $tasksFromRuns++;
                }
            }
            if ($snapshot === null && $this->taskIdentityIsEmpty($task)) {
                $snapshot = $this->snapshotFromCreationAudit(
                    $taskId,
                    $creationAudits->get($taskId, collect()),
                    $admins,
                    $findings,
                );
                if ($snapshot !== null) {
                    $snapshot['source'] = 'creation_audit';
                    $tasksFromAudit++;
                }
            }
            if ($snapshot === null && $this->taskIdentityIsEmpty($task)
                && ! $this->hasBlockingFindingFor('task', $taskId, $findings)) {
                $snapshot = [
                    'admin_id' => (int) $legacyOwner->getKey(),
                    'role' => 'super_admin',
                    'policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
                    'source' => 'legacy_owner',
                ];
                $tasksFromLegacy++;
                $findings[] = $this->finding(
                    'task',
                    $taskId,
                    'manual_review',
                    $this->taskNeedsPause($task, $taskRuns)
                        ? 'legacy_owner_inferred_active_history'
                        : 'legacy_owner_inferred_terminal_history',
                );
            }

            if ($snapshot === null) {
                continue;
            }

            $taskSnapshots[$taskId] = $snapshot;
            if ($this->taskIdentityIsEmpty($task)) {
                $taskUpdates[$taskId] = [
                    'model_access_admin_id' => $snapshot['admin_id'],
                    'model_access_admin_role' => $snapshot['role'],
                    'model_access_policy_version' => $snapshot['policy_version'],
                ];
            }
            if ($snapshot['source'] === 'legacy_owner' && $this->taskNeedsPause($task, $taskRuns)) {
                $pauseTaskIds[] = $taskId;
            }
        }

        $runUpdates = [];
        $freezeRunIds = [];
        foreach ($runs as $run) {
            $runId = (int) $run->getKey();
            $taskId = (int) $run->task_id;
            $task = $tasksById->get($taskId);
            if (! $task instanceof Task) {
                $findings[] = $this->finding('task_run', $runId, 'blocking', 'run_task_outside_snapshot');

                continue;
            }

            $snapshot = $taskSnapshots[$taskId] ?? null;
            $runState = $this->runIdentityState($run, $admins);
            if ($runState === 'partial_or_invalid') {
                $findings[] = $this->finding('task_run', $runId, 'blocking', 'partial_task_run_identity');

                continue;
            }

            if ($runState === 'complete') {
                if ($this->runConflictsWithTask($run, $task, $snapshot)) {
                    $findings[] = $this->finding('task_run', $runId, 'blocking', 'run_task_identity_conflict');

                    continue;
                }

                if ($snapshot !== null && $run->requested_ai_model_id === null && $task->ai_model_id !== null) {
                    $runUpdates[$runId] = [
                        'expected_state' => 'complete',
                        'attributes' => ['requested_ai_model_id' => (int) $task->ai_model_id],
                    ];
                }
            } elseif ($snapshot !== null) {
                $admin = $admins->get($snapshot['admin_id']);
                $attributes = [
                    'model_access_admin_id' => $snapshot['admin_id'],
                    'model_access_admin_role' => $snapshot['role'],
                    'ai_config_access_version' => max(1, (int) ($admin?->ai_config_access_version ?? 1)),
                    'resolver_policy_version' => $snapshot['policy_version'],
                ];
                if ($run->requested_ai_model_id === null && $task->ai_model_id !== null) {
                    $attributes['requested_ai_model_id'] = (int) $task->ai_model_id;
                }
                $runUpdates[$runId] = [
                    'expected_state' => 'empty',
                    'attributes' => $attributes,
                ];
            }

            if (($snapshot['source'] ?? null) === 'legacy_owner'
                && in_array((string) $run->status, ['pending', 'running'], true)) {
                $freezeRunIds[] = $runId;
            }
        }

        $legacyTaskIds = collect($taskSnapshots)
            ->filter(static fn (array $snapshot): bool => $snapshot['source'] === 'legacy_owner')
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values();
        if ($legacyTaskIds->isNotEmpty()) {
            $historicalRunIds = $runs->pluck('id')->map(static fn (mixed $id): int => (int) $id);
            TaskRun::query()
                ->whereIn('task_id', $legacyTaskIds)
                ->whereIn('status', ['pending', 'running'])
                ->whereNotIn('id', $historicalRunIds)
                ->orderBy('id')
                ->pluck('id')
                ->each(function (mixed $runId) use (&$findings): void {
                    $findings[] = $this->finding(
                        'task_run',
                        (int) $runId,
                        'blocking',
                        'active_run_outside_snapshot',
                    );
                });
        }

        usort($findings, static fn (array $left, array $right): int => [
            $left['subject_type'],
            $left['subject_id'],
            $left['reason'],
        ] <=> [
            $right['subject_type'],
            $right['subject_id'],
            $right['reason'],
        ]);

        return [
            'task_max_id' => $taskMaxId,
            'task_run_max_id' => $taskRunMaxId,
            'tasks_recovered_from_historical_runs' => $tasksFromRuns,
            'tasks_recovered_from_creation_audit' => $tasksFromAudit,
            'tasks_mapped_to_legacy_owner' => $tasksFromLegacy,
            'task_runs_inherited_from_task' => count($runUpdates),
            'legacy_inferred_tasks_to_pause' => count(array_unique($pauseTaskIds)),
            'legacy_inferred_active_runs_to_freeze' => count(array_unique($freezeRunIds)),
            'manual_execution_identity_finding_count' => count(array_filter(
                $findings,
                static fn (array $finding): bool => $finding['severity'] === 'manual_review',
            )),
            'execution_identity_blocking_conflict_count' => count(array_filter(
                $findings,
                static fn (array $finding): bool => $finding['severity'] === 'blocking',
            )),
            'task_execution_identity_findings' => $findings,
            '_task_updates' => $taskUpdates,
            '_run_updates' => $runUpdates,
            '_pause_task_ids' => array_values(array_unique($pauseTaskIds)),
            '_freeze_run_ids' => array_values(array_unique($freezeRunIds)),
        ];
    }

    /** @param array<string, mixed> $plan @return array<string, int> */
    public function applyPlan(array $plan): array
    {
        $tasksRecovered = 0;
        foreach ($plan['_task_updates'] as $taskId => $attributes) {
            $affected = Task::withTrashed()
                ->whereKey((int) $taskId)
                ->whereNull('model_access_admin_id')
                ->whereNull('model_access_admin_role')
                ->whereNull('model_access_policy_version')
                ->update([...$attributes, 'updated_at' => now()]);
            if ($affected !== 1) {
                throw new AdminAiAccessBackfillException('historical_task_execution_identity_changed');
            }
            $tasksRecovered += $affected;
        }

        $runsInherited = 0;
        foreach ($plan['_run_updates'] as $runId => $change) {
            $query = TaskRun::query()->whereKey((int) $runId);
            $attributes = $change['attributes'];
            if ($change['expected_state'] === 'empty') {
                $query
                    ->whereNull('model_access_admin_id')
                    ->whereNull('model_access_admin_role')
                    ->whereNull('ai_config_access_version')
                    ->whereNull('resolver_policy_version');
                $attributes['ai_config_access_version'] = max(1, (int) Admin::query()
                    ->whereKey((int) $attributes['model_access_admin_id'])
                    ->value('ai_config_access_version'));
            } else {
                $query->whereNull('requested_ai_model_id');
            }
            $affected = $query->update($attributes);
            if ($affected !== 1) {
                throw new AdminAiAccessBackfillException('historical_task_run_execution_identity_changed');
            }
            $runsInherited += $affected;
        }

        $tasksPaused = 0;
        foreach ($plan['_pause_task_ids'] as $taskId) {
            $tasksPaused += Task::withTrashed()
                ->whereKey((int) $taskId)
                ->where(function (Builder $query): void {
                    $query->where('status', 'active')->orWhere('schedule_enabled', 1);
                })
                ->update([
                    'status' => 'paused',
                    'schedule_enabled' => 0,
                    'next_run_at' => null,
                    'updated_at' => now(),
                ]);
        }

        $runsFrozen = 0;
        foreach ($plan['_freeze_run_ids'] as $runId) {
            $runsFrozen += TaskRun::query()
                ->whereKey((int) $runId)
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'error_code' => self::UNRESOLVED_ERROR_CODE,
                    'error_message' => 'Historical AI execution identity requires manual review.',
                    'finished_at' => now(),
                    'execution_lease_token' => null,
                ]);
        }

        return [
            'tasks_recovered' => $tasksRecovered,
            'task_runs_inherited' => $runsInherited,
            'legacy_inferred_tasks_paused' => $tasksPaused,
            'legacy_inferred_active_runs_frozen' => $runsFrozen,
        ];
    }

    public function assertSnapshotsCoverNullTimestamps(?int $taskMaxId, ?int $taskRunMaxId): void
    {
        if ($taskMaxId === null && Task::withTrashed()->whereNull('created_at')->exists()) {
            throw new AdminAiAccessBackfillException('task_max_id_required_for_null_created_at');
        }
        if ($taskRunMaxId === null && TaskRun::query()->whereNull('created_at')->exists()) {
            throw new AdminAiAccessBackfillException('task_run_max_id_required_for_null_created_at');
        }
    }

    public function lockHistoricalCandidates(
        CarbonImmutable $createdBefore,
        ?int $taskMaxId,
        ?int $taskRunMaxId,
    ): void {
        $taskIds = $this->historicalTasks($createdBefore, $taskMaxId)
            ->select(['id'])
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        $this->historicalTaskRuns($createdBefore, $taskRunMaxId)
            ->select(['id'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($taskIds->chunk(900) as $chunk) {
            AiQualityAuditEvent::query()
                ->where('event_type', 'task_quality_configuration_created')
                ->whereIn('task_id', $chunk->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
    }

    private function historicalTasks(CarbonImmutable $createdBefore, ?int $taskMaxId): Builder
    {
        $query = Task::withTrashed();
        $this->applyHistoricalBoundary($query, $createdBefore, $taskMaxId);

        return $query->orderBy('id');
    }

    private function historicalTaskRuns(CarbonImmutable $createdBefore, ?int $taskRunMaxId): Builder
    {
        $query = TaskRun::query();
        $this->applyHistoricalBoundary($query, $createdBefore, $taskRunMaxId);

        return $query->orderBy('id');
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

    /** @param list<int> $taskIds @return Collection<int, Collection<int, int>> */
    private function creationAuditAdminsByTask(array $taskIds, CarbonImmutable $createdBefore): Collection
    {
        $rows = collect();
        foreach (array_chunk($taskIds, 900) as $taskIdChunk) {
            $rows = $rows->concat(AiQualityAuditEvent::query()
                ->where('event_type', 'task_quality_configuration_created')
                ->where('authorization_result', 'allowed')
                ->whereNotNull('admin_id')
                ->whereIn('task_id', $taskIdChunk)
                ->where('occurred_at', '<=', $createdBefore->format('Y-m-d H:i:s'))
                ->orderBy('id')
                ->get(['task_id', 'admin_id']));
        }

        return $rows
            ->groupBy(static fn (AiQualityAuditEvent $event): int => (int) $event->task_id)
            ->map(static fn (Collection $events): Collection => $events
                ->pluck('admin_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values());
    }

    /** @param Collection<int, Admin> $admins @param list<array<string, int|string>> $findings */
    private function existingTaskSnapshot(Task $task, Collection $admins, array &$findings): ?array
    {
        if ($this->taskIdentityIsEmpty($task)) {
            return null;
        }

        $taskId = (int) $task->getKey();
        $adminId = $task->model_access_admin_id === null ? null : (int) $task->model_access_admin_id;
        $role = $this->normalizeSnapshotRole($task->model_access_admin_role);
        $policyVersion = $task->model_access_policy_version === null
            ? null
            : (int) $task->model_access_policy_version;
        if ($adminId === null || $role === null || $policyVersion === null
            || $policyVersion < 1 || ! $admins->has($adminId)) {
            $findings[] = $this->finding('task', $taskId, 'blocking', 'partial_task_identity');

            return null;
        }

        return [
            'admin_id' => $adminId,
            'role' => $role,
            'policy_version' => $policyVersion,
            'source' => 'existing',
        ];
    }

    /**
     * @param  Collection<int, TaskRun>  $runs
     * @param  Collection<int, Admin>  $admins
     * @param  list<array<string, int|string>>  $findings
     */
    private function snapshotFromConsistentRuns(
        int $taskId,
        Collection $runs,
        Collection $admins,
        array &$findings,
    ): ?array {
        $snapshots = $runs
            ->filter(fn (TaskRun $run): bool => $this->runIdentityState($run, $admins) === 'complete')
            ->map(static fn (TaskRun $run): string => implode(':', [
                (int) $run->model_access_admin_id,
                (string) $run->model_access_admin_role,
                (int) $run->resolver_policy_version,
            ]))
            ->unique()
            ->values();
        if ($snapshots->count() > 1) {
            $findings[] = $this->finding('task', $taskId, 'blocking', 'conflicting_historical_run_identity');

            return null;
        }
        if ($snapshots->isEmpty()) {
            return null;
        }

        [$adminId, $role, $policyVersion] = explode(':', (string) $snapshots->first());

        return [
            'admin_id' => (int) $adminId,
            'role' => $role,
            'policy_version' => (int) $policyVersion,
        ];
    }

    /**
     * @param  Collection<int, int>  $auditAdminIds
     * @param  Collection<int, Admin>  $admins
     * @param  list<array<string, int|string>>  $findings
     */
    private function snapshotFromCreationAudit(
        int $taskId,
        Collection $auditAdminIds,
        Collection $admins,
        array &$findings,
    ): ?array {
        if ($auditAdminIds->count() > 1) {
            $findings[] = $this->finding('task', $taskId, 'blocking', 'conflicting_creation_audit');

            return null;
        }
        $adminId = $auditAdminIds->first();
        if (! is_int($adminId) || ! $admins->has($adminId)) {
            return null;
        }
        /** @var Admin $admin */
        $admin = $admins->get($adminId);
        $role = $this->normalizeAdminRole($admin);
        if ($role === null) {
            return null;
        }

        return [
            'admin_id' => $adminId,
            'role' => $role,
            'policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ];
    }

    /** @param Collection<int, Admin> $admins */
    private function runIdentityState(TaskRun $run, Collection $admins): string
    {
        $values = [
            $run->model_access_admin_id,
            $run->model_access_admin_role,
            $run->ai_config_access_version,
            $run->resolver_policy_version,
        ];
        $nonNullCount = count(array_filter($values, static fn (mixed $value): bool => $value !== null));
        if ($nonNullCount === 0) {
            return 'empty';
        }
        if ($nonNullCount !== count($values)) {
            return 'partial_or_invalid';
        }

        $adminId = (int) $run->model_access_admin_id;
        $role = $this->normalizeSnapshotRole($run->model_access_admin_role);

        return $adminId > 0
            && $admins->has($adminId)
            && $role !== null
            && (int) $run->ai_config_access_version > 0
            && (int) $run->resolver_policy_version > 0
            ? 'complete'
            : 'partial_or_invalid';
    }

    /** @param array<string, int|string>|null $snapshot */
    private function runConflictsWithTask(TaskRun $run, Task $task, ?array $snapshot): bool
    {
        $adminId = $snapshot['admin_id'] ?? $task->model_access_admin_id;
        $role = $snapshot['role'] ?? $this->normalizeSnapshotRole($task->model_access_admin_role);
        $policyVersion = $snapshot['policy_version'] ?? $task->model_access_policy_version;

        return ($adminId !== null && (int) $run->model_access_admin_id !== (int) $adminId)
            || ($role !== null && (string) $run->model_access_admin_role !== (string) $role)
            || ($policyVersion !== null && (int) $run->resolver_policy_version !== (int) $policyVersion);
    }

    /** @param Collection<int, TaskRun> $runs */
    private function taskNeedsPause(Task $task, Collection $runs): bool
    {
        return (string) $task->status === 'active'
            || (int) $task->schedule_enabled === 1
            || $runs->contains(static fn (TaskRun $run): bool => in_array(
                (string) $run->status,
                ['pending', 'running'],
                true,
            ));
    }

    private function taskIdentityIsEmpty(Task $task): bool
    {
        return $task->model_access_admin_id === null
            && $task->model_access_admin_role === null
            && $task->model_access_policy_version === null;
    }

    private function normalizeSnapshotRole(mixed $role): ?string
    {
        $normalized = strtolower(trim((string) $role));

        return in_array($normalized, ['admin', 'super_admin'], true) ? $normalized : null;
    }

    private function normalizeAdminRole(Admin $admin): ?string
    {
        if ($admin->isSuperAdmin()) {
            return 'super_admin';
        }

        return strtolower(trim((string) $admin->role)) === 'admin' ? 'admin' : null;
    }

    /** @param list<array<string, int|string>> $findings */
    private function hasBlockingFindingFor(string $subjectType, int $subjectId, array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding['subject_type'] === $subjectType
                && $finding['subject_id'] === $subjectId
                && $finding['severity'] === 'blocking') {
                return true;
            }
        }

        return false;
    }

    /** @return array{subject_type: string, subject_id: int, severity: string, reason: string} */
    private function finding(string $subjectType, int $subjectId, string $severity, string $reason): array
    {
        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'severity' => $severity,
            'reason' => $reason,
        ];
    }
}
