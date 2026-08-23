<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiCapabilityResult;
use App\Ai\Workspace\AiOutcomeUnknownException;
use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiPlanCompiler;
use App\Ai\Workspace\AiWorkflowPlan;
use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Jobs\ProcessAiWorkspaceRunJob;
use App\Models\Admin;
use App\Models\AiWorkspaceApproval;
use App\Models\AiWorkspaceArtifact;
use App\Models\AiWorkspaceExternalOperation;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceStep;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class AiWorkflowEngine
{
    public function __construct(
        private AiCapabilityRegistry $registry,
        private AiPlanCompiler $compiler,
        private AiCapabilityExecutor $executor,
        private AiWorkspaceStateMachine $states,
        private AiWorkspaceRealtimeService $realtime,
        private AiConversationRepository $conversations,
    ) {}

    public function prepare(AiWorkspaceRun $run, AiWorkflowPlan $plan, ?string $resolutionLeaseOwner = null): AiWorkspaceRun
    {
        $run = DB::transaction(function () use ($run, $plan, $resolutionLeaseOwner): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (is_string($resolutionLeaseOwner)
                && (! hash_equals((string) $locked->resolution_lease_owner, $resolutionLeaseOwner)
                    || ! $locked->resolution_lease_expires_at?->isFuture())) {
                throw new RuntimeException('请求理解租约已经失效。');
            }
            if (! in_array((string) $locked->state, ['planning', 'validating_plan'], true)) {
                throw new RuntimeException('运行状态不允许写入计划。');
            }
            if ($locked->state === 'planning') {
                $locked = $this->states->transition($locked, 'validating_plan');
            }

            if (is_array($locked->plan) && (int) $locked->plan_version !== $plan->version) {
                $locked->artifacts()->create([
                    'id' => (string) Str::uuid7(),
                    'step_id' => null,
                    'created_by_admin_id' => $locked->admin_id,
                    'created_by_username_snapshot' => $locked->admin_username_snapshot,
                    'type' => 'plan_revision',
                    'name' => 'AI 工作流计划 v'.$locked->plan_version,
                    'data_classification' => 'internal',
                    'content' => '已失效的历史计划快照',
                    'payload' => [
                        'plan' => $locked->plan,
                        'plan_digest' => $locked->plan_digest,
                        'parameter_digest' => $locked->parameter_digest,
                        'target_digest' => $locked->target_digest,
                    ],
                    'expires_at' => now()->addDays((int) config('ai-workspace.retention_days', 90)),
                ]);
            }

            $locked->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decided_at' => now(),
                'decision_reason' => '计划版本已变更',
            ]);
            $locked->approvals()->whereNotNull('step_id')->update(['step_id' => null]);
            $locked->steps()->delete();
            foreach ($plan->steps as $step) {
                $locked->steps()->create([
                    'id' => (string) Str::uuid7(),
                    'position' => (int) $step['position'],
                    'capability_key' => (string) $step['capability'],
                    'capability_name' => (string) $step['capability_name'],
                    'capability_version' => (string) $step['capability_version'],
                    'state' => 'pending',
                    'risk_level' => (string) $step['risk_level'],
                    'execution_scope' => (string) $step['execution_scope'],
                    'approval_policy' => (string) $step['approval_policy'],
                    'result_contract' => (array) $step['result_contract'],
                    'parameters' => (array) $step['parameters'],
                    'depends_on' => (array) ($step['depends_on'] ?? []),
                    'input_bindings' => (array) ($step['input_bindings'] ?? []),
                    'target_summary' => (array) $step['target_summary'],
                    'idempotency_key' => 'aiw:'.hash('sha256', $locked->id.'|'.$plan->version.'|'.$step['position'].'|'.$plan->digest),
                    'requires_approval' => (bool) $step['requires_approval'],
                    'external_operation' => (bool) $step['external_operation'],
                    'max_attempts' => (bool) $step['external_operation'] ? 1 : 3,
                ]);
            }
            $locked->forceFill([
                'plan' => $plan->toArray(),
                'plan_version' => $plan->version,
                'plan_digest' => $plan->digest,
                'capability_versions' => $plan->capabilityVersions,
                'parameter_digest' => $plan->parameterDigest,
                'target_digest' => $plan->targetDigest,
                'risk_level' => $plan->riskLevel,
                'status_message' => $plan->requiresApproval() ? '计划已校验，等待确认。' : '计划已校验，等待执行。',
                'resolution_lease_owner' => is_string($resolutionLeaseOwner) ? null : $locked->resolution_lease_owner,
                'resolution_lease_expires_at' => is_string($resolutionLeaseOwner) ? null : $locked->resolution_lease_expires_at,
            ])->save();

            if ($plan->requiresApproval()) {
                $groupedCapabilities = [];
                $perStepApprovalCreated = false;
                foreach ($locked->steps()->where('requires_approval', true)->orderBy('position')->get() as $step) {
                    if ((array) $step->input_bindings !== [] && $step->bindings_resolved_at === null) {
                        continue;
                    }
                    if ($step->approval_policy === 'per_step') {
                        if (! $perStepApprovalCreated) {
                            $this->createApproval($locked, $step, false);
                            $perStepApprovalCreated = true;
                        }

                        continue;
                    }
                    if (! isset($groupedCapabilities[$step->capability_key])) {
                        $this->createApproval($locked, $step, true);
                        $groupedCapabilities[$step->capability_key] = true;
                    }
                }

                if ($locked->approvals()->where('status', 'pending')->exists()) {
                    return $this->states->transition($locked, 'awaiting_approval');
                }
            }

            $queued = $this->states->transition($locked, 'queued');
            DB::afterCommit(fn () => $this->dispatch($queued));

            return $queued;
        });
        $this->realtime->broadcast($run);

        return $run;
    }

    public function approve(Admin $admin, AiWorkspaceApproval $approval): AiWorkspaceRun
    {
        $run = DB::transaction(function () use ($admin, $approval): AiWorkspaceRun {
            $lockedApproval = AiWorkspaceApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($lockedApproval->run_id);
            $this->assertOwner($admin, $run);
            if (! in_array((string) $run->state, ['awaiting_approval', 'awaiting_step_approval'], true)) {
                throw new RuntimeException('当前运行状态不允许审批。');
            }
            if ($run->approvals()->where('status', 'pending')->where('expires_at', '<=', now())->exists()) {
                throw new RuntimeException('审批已失效：计划中存在过期项，请重新生成计划。');
            }
            if ($lockedApproval->status !== 'pending' || $lockedApproval->expires_at?->isPast()) {
                throw new RuntimeException('审批已失效，请重新生成计划。');
            }
            if ((int) $lockedApproval->plan_version !== (int) $run->plan_version
                || ! hash_equals((string) $lockedApproval->parameter_digest, (string) $run->parameter_digest)
                || ! hash_equals((string) $lockedApproval->target_digest, (string) $run->target_digest)
                || $lockedApproval->capability_versions !== $this->registry->versions(array_keys((array) $run->capability_versions))) {
                throw new RuntimeException('计划或能力版本已经变化，请重新确认。');
            }
            $this->assertPlanTargetsUnchanged($run);
            $lockedApproval->forceFill(['status' => 'approved', 'decided_at' => now()])->save();

            $hasPending = $run->approvals()->where('status', 'pending')->where('expires_at', '>', now())->exists();
            if ($hasPending) {
                return $this->states->touchEvent($run, ['status_message' => '已确认一项，请继续审批剩余操作。']);
            }
            $allApproved = $run->steps()
                ->where('requires_approval', true)
                ->whereNotIn('state', ['completed', 'skipped'])
                ->get()
                ->every(function (AiWorkspaceStep $step) use ($run): bool {
                    if (((array) $step->input_bindings !== [] && $step->bindings_resolved_at === null) || $this->hasValidApproval($run, $step)) {
                        return true;
                    }

                    return $step->approval_policy === 'per_step';
                });
            if (! $allApproved) {
                throw new RuntimeException('计划缺少完整有效的审批，请重新确认。');
            }

            $queued = $this->states->transition($run, 'queued', ['status_message' => '审批完成，等待执行。']);
            DB::afterCommit(fn () => $this->dispatch($queued));

            return $queued;
        });
        $this->realtime->broadcast($run);

        return $run;
    }

    public function reject(Admin $admin, AiWorkspaceApproval $approval, ?string $reason = null): AiWorkspaceRun
    {
        $run = DB::transaction(function () use ($admin, $approval, $reason): AiWorkspaceRun {
            $lockedApproval = AiWorkspaceApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($lockedApproval->run_id);
            $this->assertOwner($admin, $run);
            if (! in_array((string) $run->state, ['awaiting_approval', 'awaiting_step_approval'], true)) {
                throw new RuntimeException('当前运行状态不允许处理审批。');
            }
            if ($lockedApproval->status !== 'pending') {
                throw new RuntimeException('审批已经处理。');
            }
            $lockedApproval->forceFill([
                'status' => 'rejected',
                'decision_reason' => $reason,
                'decided_at' => now(),
            ])->save();
            $run->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decision_reason' => '同一计划已拒绝',
                'decided_at' => now(),
            ]);

            return $this->states->transition($run, 'rejected', ['status_message' => '计划已拒绝。']);
        });
        $this->realtime->broadcast($run);

        return $run;
    }

    /** @param array<string,array<string,mixed>> $stepParameters */
    public function editPlan(Admin $admin, AiWorkspaceRun $run, array $stepParameters, int $expectedPlanVersion): AiWorkspaceRun
    {
        return DB::transaction(function () use ($admin, $run, $stepParameters, $expectedPlanVersion): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertOwner($admin, $locked);
            if ((int) $locked->plan_version !== $expectedPlanVersion) {
                throw new RuntimeException('计划版本已变化，请刷新后重试。');
            }
            if (! in_array((string) $locked->state, ['awaiting_approval', 'awaiting_step_approval', 'failed'], true)) {
                throw new RuntimeException('当前状态不允许修改计划。');
            }
            if ($locked->payload_pruned_at !== null) {
                throw new RuntimeException('运行载荷已按留存策略清理，不能再修改计划。');
            }
            $steps = $locked->steps()->orderBy('position')->lockForUpdate()->get();
            if ($steps->contains(static fn (AiWorkspaceStep $step): bool => $step->state === 'completed')) {
                throw new RuntimeException('计划已有完成步骤，请保留现有结果并从后续操作创建新请求。');
            }
            $unknownStepIds = array_diff(array_keys($stepParameters), $steps->modelKeys());
            if ($unknownStepIds !== []) {
                throw new RuntimeException('计划参数包含未知步骤。');
            }
            $drafts = $steps->map(function (AiWorkspaceStep $step) use ($stepParameters): array {
                return [
                    'capability' => (string) $step->capability_key,
                    'parameters' => $stepParameters[(string) $step->id] ?? $step->parameters,
                    'depends_on' => (array) $step->depends_on,
                    'input_bindings' => (array) $step->input_bindings,
                ];
            })->all();
            try {
                $plan = $this->compiler->compile($admin, (string) $locked->intent, $drafts, $expectedPlanVersion + 1);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'step_parameters' => [AiWorkspaceErrorSanitizer::clean($exception->getMessage())],
                ]);
            }
            $planning = $this->states->transition($locked, 'planning', ['status_message' => '正在校验修改后的计划。']);

            return $this->prepare($planning, $plan);
        });
    }

    public function cancel(Admin $admin, AiWorkspaceRun $run): AiWorkspaceRun
    {
        $cancelled = DB::transaction(function () use ($admin, $run): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertOwner($admin, $locked);
            if ($locked->isTerminal()) {
                return $locked;
            }
            $locked->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decision_reason' => '运行已取消',
                'decided_at' => now(),
            ]);
            $state = $locked->state === 'running' ? 'cancel_requested' : 'cancelled';

            return $this->states->transition($locked, $state, [
                'cancel_requested_at' => now(),
                'status_message' => $state === 'cancelled' ? '运行已取消。' : '已请求取消，正在等待当前步骤结束。',
            ]);
        });
        $this->realtime->broadcast($cancelled);

        return $cancelled;
    }

    public function retryStep(Admin $admin, AiWorkspaceStep $step): AiWorkspaceRun
    {
        $run = $step->run()->firstOrFail();
        $this->assertOwner($admin, $run);
        if ($run->payload_pruned_at !== null) {
            throw new RuntimeException('运行载荷已按留存策略清理，不能再重试。');
        }
        if (! in_array((string) $run->state, ['failed', 'partially_completed'], true)) {
            throw new RuntimeException('运行尚未结束，不能同时启动重试。');
        }
        if ($step->state !== 'failed' || (bool) $step->external_operation || $step->attempts >= $step->max_attempts) {
            throw new RuntimeException('该步骤不允许自动重试。');
        }
        DB::transaction(function () use ($step, $run): void {
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array((string) $lockedRun->state, ['failed', 'partially_completed'], true)) {
                throw new RuntimeException('运行重试状态已经变化。');
            }
            if ($lockedRun->payload_pruned_at !== null) {
                throw new RuntimeException('运行载荷已按留存策略清理，不能再重试。');
            }
            $locked = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            if ($locked->state !== 'failed' || $locked->external_operation || $locked->attempts >= $locked->max_attempts) {
                throw new RuntimeException('该步骤的重试状态已经变化。');
            }
            $locked->forceFill(['state' => 'pending', 'error_message' => null, 'lease_owner' => null, 'lease_expires_at' => null])->save();
            $this->reviveSkippedDependents($lockedRun, (int) $locked->position);
            $queued = $this->states->transition($lockedRun, 'queued', [
                'failure_code' => null,
                'failure_message' => null,
                'finished_at' => null,
                'status_message' => '失败步骤已重新入队。',
            ]);
            DB::afterCommit(fn () => $this->dispatch($queued));
        });

        $fresh = $run->fresh();
        $this->realtime->broadcast($fresh);

        return $fresh;
    }

    public function process(string $runId, ?string $workerToken = null): void
    {
        $workerToken ??= (string) Str::uuid7();
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            $this->stopForDisabledRuntime($runId);

            return;
        }

        $concurrencyCache = Cache::store(app()->environment('testing')
            ? (string) config('cache.default')
            : (string) config('ai-workspace.concurrency_cache_store', 'redis'));
        $claimLock = $concurrencyCache->lock('ai-workspace:claim', 10);
        if (! $claimLock->get()) {
            $this->defer($runId);

            return;
        }
        try {
            $running = AiWorkspaceRun::query()->where('state', 'running')->count()
                + (int) $concurrencyCache->get('ai-workspace:model-calls', 0);
            if ($running >= (int) config('ai-workspace.global_concurrency', 10)) {
                $this->defer($runId);

                return;
            }
            $claim = DB::transaction(function () use ($runId): array {
                $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
                if ($run->state === 'cancel_requested') {
                    return [
                        'run' => $this->states->transition($run, 'cancelled', ['status_message' => '运行已取消。']),
                        'claimed' => false,
                    ];
                }
                if ($run->state !== 'queued') {
                    return ['run' => $run, 'claimed' => false];
                }

                return [
                    'run' => $this->states->transition($run, 'running', [
                        'started_at' => $run->started_at ?? now(),
                        'status_message' => '工作流正在执行。',
                    ]),
                    'claimed' => true,
                ];
            });
        } finally {
            $claimLock->release();
        }

        /** @var AiWorkspaceRun $run */
        $run = $claim['run'];
        if (! $claim['claimed']) {
            $this->realtime->broadcast($run);

            return;
        }
        $this->realtime->broadcast($run);

        $admin = $this->currentAdminForRun($run);
        if (! $admin instanceof Admin) {
            $this->failRun($run, 'authorization_revoked', '管理员已停用、不存在或授权版本已变化。');

            return;
        }

        foreach ($run->steps()->orderBy('position')->get() as $step) {
            $run->refresh();
            $admin = $this->currentAdminForRun($run);
            if (! $admin instanceof Admin) {
                $this->failRun($run, 'authorization_revoked', '管理员授权已变化，已停止后续步骤。');

                return;
            }
            if (! (bool) config('ai-workspace.runtime_enabled', false)) {
                $this->stopForDisabledRuntime((string) $run->id);

                return;
            }
            if ($run->state === 'cancel_requested') {
                $final = $run->steps()->where('state', 'completed')->exists() ? 'partially_completed' : 'cancelled';
                $run = $this->states->transitionLocked((string) $run->id, $final, ['status_message' => '运行已按请求停止。']);
                $this->realtime->broadcast($run);

                return;
            }
            $step->refresh();
            if (in_array((string) $step->state, ['completed', 'failed', 'skipped', 'outcome_unknown'], true)) {
                continue;
            }

            $dependencyState = $this->dependencyState($run, $step);
            if ($dependencyState === 'blocked') {
                $run = $this->skipBlockedStep($run, $step);
                $this->realtime->broadcast($run);

                continue;
            }
            if ($dependencyState === 'waiting') {
                continue;
            }

            $leaseOwner = null;
            try {
                $step = $this->resolveInputBindings($run, $step);
                $capability = $this->registry->get((string) $step->capability_key);
                if (! $capability->allows($admin) || $capability->version !== $step->capability_version) {
                    throw new RuntimeException('执行前权限或能力版本校验失败。');
                }
                if ($step->requires_approval && ! $this->hasValidApproval($run, $step)) {
                    $awaiting = $this->awaitStepApproval($run, $step);
                    $this->realtime->broadcast($awaiting);

                    return;
                }
                $leaseOwner = $this->claimStep($step, $workerToken);
                if (str_starts_with((string) $step->execution_scope, 'external_')) {
                    if ($step->capability_key === 'distribution.publish') {
                        $run = DB::transaction(function () use ($run, $step, $capability, $admin, $leaseOwner): AiWorkspaceRun {
                            $this->lockAndAssertLeaseOwned($step, $leaseOwner);
                            $this->compiler->lockTargetsFor((string) $step->capability_key, (array) $step->parameters);
                            $this->assertTargetUnchanged($step);
                            $result = $this->executor->execute($step->capability_key, $step->parameters, $admin, (string) $step->idempotency_key);

                            return $this->recordCompletedStep($run, $step, $result, $capability->dataClassification, $admin, $leaseOwner);
                        });
                    } else {
                        DB::transaction(function () use ($step, $leaseOwner): void {
                            $lockedStep = $this->lockAndAssertLeaseOwned($step, $leaseOwner);
                            $this->compiler->lockTargetsFor((string) $lockedStep->capability_key, (array) $lockedStep->parameters);
                            $this->assertTargetUnchanged($lockedStep);
                            $this->executor->prepareExternalExecution($lockedStep);
                        });
                        $admin = $this->currentAdminForRun($run);
                        if (! $admin instanceof Admin || ! $capability->allows($admin)) {
                            throw new RuntimeException('外部请求发出前授权已变化。');
                        }
                        $result = $this->executor->execute($step->capability_key, $step->parameters, $admin, (string) $step->idempotency_key);
                        $run = DB::transaction(fn (): AiWorkspaceRun => $this->recordCompletedStep($run, $step, $result, $capability->dataClassification, $admin, $leaseOwner));
                    }
                } else {
                    // Internal mutations and their execution record commit atomically.
                    $run = DB::transaction(function () use ($run, $step, $capability, $admin, $leaseOwner): AiWorkspaceRun {
                        $this->lockAndAssertLeaseOwned($step, $leaseOwner);
                        $this->compiler->lockTargetsFor((string) $step->capability_key, (array) $step->parameters);
                        $this->assertTargetUnchanged($step);
                        $result = $this->executor->execute($step->capability_key, $step->parameters, $admin, (string) $step->idempotency_key);

                        return $this->recordCompletedStep($run, $step, $result, $capability->dataClassification, $admin, $leaseOwner);
                    });
                }
                $this->realtime->broadcast($run);
            } catch (AiOutcomeUnknownException $exception) {
                $run = is_string($leaseOwner)
                    ? $this->recordClaimedOutcomeUnknown($run, $step, $leaseOwner, $exception->getMessage())
                    : null;
                if (! $run instanceof AiWorkspaceRun) {
                    return;
                }
                $this->realtime->broadcast($run);

                return;
            } catch (Throwable $exception) {
                $step->refresh();
                $run->refresh();
                if ($run->isTerminal() || in_array((string) $step->state, ['completed', 'outcome_unknown'], true)) {
                    $this->realtime->broadcast($run);

                    return;
                }
                $external = (bool) $step->external_operation;
                $message = AiWorkspaceErrorSanitizer::clean($exception->getMessage());
                $requiresReconciliation = $external && AiWorkspaceExternalOperation::query()
                    ->where('step_id', $step->id)
                    ->whereIn('status', ['dispatched', 'confirmed'])
                    ->exists();
                if ($external && is_string($leaseOwner) && ($requiresReconciliation || $this->looksLikeUnknownOutcome($exception) || str_contains($message, '租约'))) {
                    $reconciled = $this->reconcileClaimedExternalStep($run, $step, $admin, $leaseOwner);
                    if ($reconciled instanceof AiWorkspaceRun) {
                        $run = $reconciled;
                        $this->realtime->broadcast($run);

                        continue;
                    }
                    $run = is_string($leaseOwner)
                        ? $this->recordClaimedOutcomeUnknown($run, $step, $leaseOwner, $message)
                        : null;
                    if (! $run instanceof AiWorkspaceRun) {
                        return;
                    }
                    $this->realtime->broadcast($run);

                    return;
                } else {
                    $run = is_string($leaseOwner)
                        ? $this->recordClaimedFailure($run, $step, $leaseOwner, $message)
                        : $this->recordUnclaimedFailure($run, $step, $message);
                    if (! $run instanceof AiWorkspaceRun) {
                        return;
                    }
                }
                $this->realtime->broadcast($run);
            }
        }

        $run->refresh();
        $states = $run->steps()->pluck('state');
        $completedCount = $states->filter(static fn (string $state): bool => $state === 'completed')->count();
        $hasFailures = $states->contains(static fn (string $state): bool => in_array($state, ['failed', 'skipped'], true));
        $hasUnfinished = $states->contains(static fn (string $state): bool => in_array($state, ['pending', 'running'], true));
        if ($hasUnfinished) {
            $this->failRun($run, 'dependency_deadlock', '工作流依赖无法继续，请重新生成计划。');

            return;
        }
        $terminal = $hasFailures
            ? ($completedCount > 0 ? 'partially_completed' : 'failed')
            : 'completed';
        $summary = $run->artifacts()->where('type', '!=', 'plan_revision')->orderBy('created_at')->pluck('content')->filter()->implode("\n");
        $run = DB::transaction(function () use ($run, $terminal, $summary, $hasFailures, $completedCount, $admin): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $completed = $this->states->transition($locked, $terminal, [
                'answer' => $summary,
                'failure_code' => $hasFailures ? 'step_failed' : null,
                'failure_message' => $hasFailures
                    ? ((string) $locked->failure_message !== '' ? (string) $locked->failure_message : '一个或多个步骤失败或因依赖失败而跳过。')
                    : null,
                'status_message' => $terminal === 'completed' ? '工作流已完成。' : '工作流已部分完成。',
                'system_operations_executed' => $completedCount > 0,
            ]);
            $conversation = $this->conversations->findForAdmin($admin, (string) $completed->conversation_id, true);
            $this->conversations->append($conversation, 'assistant', $summary, [
                'run_id' => $completed->id,
                'system_operations_executed' => $completedCount > 0,
            ]);

            return $completed;
        });
        $this->realtime->broadcast($run);
    }

    private function dependencyState(AiWorkspaceRun $run, AiWorkspaceStep $step): string
    {
        $dependencies = array_values(array_unique(array_map('intval', (array) $step->depends_on)));
        if ($dependencies === []) {
            return 'ready';
        }
        $states = $run->steps()->whereIn('position', $dependencies)->pluck('state', 'position');
        if ($states->count() !== count($dependencies)
            || $states->contains(static fn (string $state): bool => in_array($state, ['failed', 'skipped', 'outcome_unknown'], true))) {
            return 'blocked';
        }

        return $states->every(static fn (string $state): bool => $state === 'completed') ? 'ready' : 'waiting';
    }

    private function skipBlockedStep(AiWorkspaceRun $run, AiWorkspaceStep $step): AiWorkspaceRun
    {
        return DB::transaction(function () use ($run, $step): AiWorkspaceRun {
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array((string) $lockedStep->state, ['pending', 'failed'], true)) {
                return $lockedRun;
            }
            $lockedStep->forceFill([
                'state' => 'skipped',
                'error_message' => '依赖步骤未成功，当前分支已跳过。',
                'finished_at' => now(),
            ])->save();

            return $this->states->touchEvent($lockedRun, ['status_message' => '已跳过依赖失败的步骤，继续处理独立分支。']);
        });
    }

    private function resolveInputBindings(AiWorkspaceRun $run, AiWorkspaceStep $step): AiWorkspaceStep
    {
        $bindings = (array) $step->input_bindings;
        if ($bindings === [] || $step->bindings_resolved_at !== null) {
            return $step;
        }

        return DB::transaction(function () use ($run, $step, $bindings): AiWorkspaceStep {
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            if ($lockedStep->state !== 'pending') {
                return $lockedStep;
            }
            $parameters = (array) $lockedStep->parameters;
            foreach ($bindings as $field => $binding) {
                $source = $lockedRun->steps()->where('position', (int) ($binding['step'] ?? 0))->first();
                $artifact = $source?->artifacts()->first();
                $value = $artifact instanceof AiWorkspaceArtifact
                    ? data_get((array) $artifact->payload, (string) ($binding['path'] ?? ''))
                    : null;
                if ($value === null || $value === '') {
                    throw new RuntimeException('前序步骤没有产生参数“'.$field.'”所需的结果。');
                }
                $parameters[(string) $field] = $value;
            }
            $parameters = $this->compiler->validateParametersFor((string) $lockedStep->capability_key, $parameters);
            $targetSummary = $this->compiler->targetSummaryFor((string) $lockedStep->capability_key, $parameters);
            $lockedStep->forceFill([
                'parameters' => $parameters,
                'target_summary' => $targetSummary,
                'bindings_resolved_at' => now(),
            ])->save();

            $plan = (array) $lockedRun->plan;
            $planSteps = $lockedRun->steps()->orderBy('position')->get();
            foreach ((array) ($plan['steps'] ?? []) as $index => $planStep) {
                $current = $planSteps->firstWhere('position', (int) ($planStep['position'] ?? $index + 1));
                if ($current instanceof AiWorkspaceStep) {
                    $plan['steps'][$index]['parameters'] = (array) $current->parameters;
                    $plan['steps'][$index]['input_bindings'] = (array) $current->input_bindings;
                    $plan['steps'][$index]['target_summary'] = (array) $current->target_summary;
                }
            }
            $parameterDigest = AiPayloadDigest::make($planSteps->map(static fn (AiWorkspaceStep $item): array => [
                'parameters' => (array) $item->parameters,
                'input_bindings' => (array) $item->input_bindings,
            ])->all());
            $targetDigest = AiPayloadDigest::make($planSteps->pluck('target_summary')->all());
            $plan['parameter_digest'] = $parameterDigest;
            $plan['target_digest'] = $targetDigest;
            unset($plan['digest']);
            $planDigest = AiPayloadDigest::make($plan);
            $plan['digest'] = $planDigest;
            $lockedRun->approvals()->whereIn('status', ['pending', 'approved'])->update([
                'status' => 'expired',
                'decision_reason' => '前序产物已绑定，参数摘要发生变化',
                'decided_at' => now(),
            ]);
            $this->states->touchEvent($lockedRun, [
                'plan' => $plan,
                'plan_digest' => $planDigest,
                'parameter_digest' => $parameterDigest,
                'target_digest' => $targetDigest,
                'status_message' => '已绑定前序结果，正在校验后续步骤。',
            ]);

            return $lockedStep->refresh();
        });
    }

    private function recordClaimedFailure(AiWorkspaceRun $run, AiWorkspaceStep $step, string $leaseOwner, string $message): ?AiWorkspaceRun
    {
        return DB::transaction(function () use ($run, $step, $leaseOwner, $message): ?AiWorkspaceRun {
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($lockedStep->state !== 'running' || ! hash_equals((string) $lockedStep->lease_owner, $leaseOwner)) {
                return null;
            }
            $lockedStep->forceFill([
                'state' => 'failed',
                'error_message' => $message,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $this->states->touchEvent($lockedRun, [
                'failure_code' => 'step_failed',
                'failure_message' => $message,
                'status_message' => '步骤执行失败，正在检查独立分支。',
            ]);
        });
    }

    private function recordUnclaimedFailure(AiWorkspaceRun $run, AiWorkspaceStep $step, string $message): ?AiWorkspaceRun
    {
        return DB::transaction(function () use ($run, $step, $message): ?AiWorkspaceRun {
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array((string) $lockedStep->state, ['pending', 'failed'], true)) {
                return null;
            }
            $lockedStep->forceFill([
                'state' => 'failed',
                'error_message' => $message,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $this->states->touchEvent($lockedRun, [
                'failure_code' => 'step_validation_failed',
                'failure_message' => $message,
                'status_message' => '步骤校验失败，正在检查独立分支。',
            ]);
        });
    }

    private function recordClaimedOutcomeUnknown(AiWorkspaceRun $run, AiWorkspaceStep $step, string $leaseOwner, string $message): ?AiWorkspaceRun
    {
        return DB::transaction(function () use ($run, $step, $leaseOwner, $message): ?AiWorkspaceRun {
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($lockedStep->state !== 'running' || ! hash_equals((string) $lockedStep->lease_owner, $leaseOwner)) {
                return null;
            }
            $lockedStep->forceFill([
                'state' => 'outcome_unknown',
                'error_message' => $message,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $this->states->transition($lockedRun, 'outcome_unknown', [
                'failure_code' => 'outcome_unknown',
                'failure_message' => $message,
                'status_message' => '外部结果无法确认，已停止自动重试。',
            ]);
        });
    }

    private function claimStep(AiWorkspaceStep $step, string $leaseOwner): string
    {
        $updated = AiWorkspaceStep::query()
            ->whereKey($step->id)
            ->whereIn('state', ['pending', 'failed'])
            ->whereColumn('attempts', '<', 'max_attempts')
            ->update([
                'state' => 'running',
                'attempts' => DB::raw('attempts + 1'),
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => now()->addMinutes((int) config('ai-workspace.step_lease_minutes', 20)),
                'started_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('步骤已由其他执行器领取。');
        }
        $step->refresh();

        return $leaseOwner;
    }

    private function assertLeaseOwned(AiWorkspaceStep $step, string $leaseOwner): void
    {
        $owned = AiWorkspaceStep::query()
            ->whereKey($step->id)
            ->where('state', 'running')
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', now())
            ->exists();
        if (! $owned) {
            throw new RuntimeException('步骤执行租约已经失效。');
        }
    }

    private function lockAndAssertLeaseOwned(AiWorkspaceStep $step, string $leaseOwner): AiWorkspaceStep
    {
        $locked = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
        if ($locked->state !== 'running'
            || ! hash_equals((string) $locked->lease_owner, $leaseOwner)
            || ! $locked->lease_expires_at?->isFuture()) {
            throw new RuntimeException('步骤执行租约已经失效。');
        }

        return $locked;
    }

    private function assertTargetUnchanged(AiWorkspaceStep $step): void
    {
        $currentTarget = $this->compiler->targetSummaryFor((string) $step->capability_key, (array) $step->parameters);
        if (! hash_equals(
            AiPayloadDigest::make((array) $step->target_summary),
            AiPayloadDigest::make($currentTarget),
        )) {
            throw new RuntimeException('目标对象在审批后已变化，请刷新计划并重新确认。');
        }
    }

    private function assertPlanTargetsUnchanged(AiWorkspaceRun $run): void
    {
        try {
            foreach ($run->steps()->where('state', '!=', 'completed')->orderBy('position')->get() as $step) {
                if ((array) $step->input_bindings !== [] && $step->bindings_resolved_at === null) {
                    continue;
                }
                $this->assertTargetUnchanged($step);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('目标对象在计划生成后已变化，请刷新计划并重新确认。', 0, $exception);
        }
    }

    private function hasValidApproval(AiWorkspaceRun $run, AiWorkspaceStep $step): bool
    {
        $query = $run->approvals()
            ->where('capability_key', $step->capability_key)
            ->where('status', 'approved')
            ->where('expires_at', '>', now());
        $step->approval_policy === 'per_step'
            ? $query->where('step_id', $step->id)
            : $query->whereNull('step_id');

        return $query->get()
            ->contains(fn (AiWorkspaceApproval $approval): bool => $approval->isValidFor($run));
    }

    private function createApproval(AiWorkspaceRun $run, AiWorkspaceStep $step, bool $grouped): AiWorkspaceApproval
    {
        return $run->approvals()->create([
            'id' => (string) Str::uuid7(),
            'step_id' => $grouped ? null : $step->id,
            'capability_key' => $step->capability_key,
            'admin_id' => $run->admin_id,
            'admin_username_snapshot' => $run->admin_username_snapshot,
            'status' => 'pending',
            'plan_version' => $run->plan_version,
            'capability_versions' => $run->capability_versions,
            'parameter_digest' => $run->parameter_digest,
            'target_digest' => $run->target_digest,
            'expires_at' => now()->addMinutes((int) config('ai-workspace.approval_ttl_minutes', 30)),
        ]);
    }

    private function awaitStepApproval(AiWorkspaceRun $run, AiWorkspaceStep $step): AiWorkspaceRun
    {
        return DB::transaction(function () use ($run, $step): AiWorkspaceRun {
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun->approvals()
                ->whereIn('status', ['pending', 'approved'])
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => 'expired',
                    'decision_reason' => '审批已过期，等待续批',
                    'decided_at' => now(),
                ]);
            $groupedCapabilities = [];
            foreach ($lockedRun->steps()->where('requires_approval', true)->whereNotIn('state', ['completed', 'skipped'])->orderBy('position')->get() as $approvalStep) {
                if ((array) $approvalStep->input_bindings !== [] && $approvalStep->bindings_resolved_at === null) {
                    continue;
                }
                $grouped = $approvalStep->approval_policy !== 'per_step';
                if (! $grouped && $approvalStep->id !== $lockedStep->id) {
                    continue;
                }
                if ($grouped && isset($groupedCapabilities[$approvalStep->capability_key])) {
                    continue;
                }
                $groupedCapabilities[$approvalStep->capability_key] = true;
                $existing = $lockedRun->approvals()
                    ->where('capability_key', $approvalStep->capability_key)
                    ->when($grouped, static fn ($query) => $query->whereNull('step_id'))
                    ->when(! $grouped, static fn ($query) => $query->where('step_id', $approvalStep->id))
                    ->where('plan_version', $lockedRun->plan_version)
                    ->where('parameter_digest', $lockedRun->parameter_digest)
                    ->where('target_digest', $lockedRun->target_digest)
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->exists();
                if (! $existing && ! $this->hasValidApproval($lockedRun, $approvalStep)) {
                    $this->createApproval($lockedRun, $approvalStep, $grouped);
                }
            }

            return $this->states->transition($lockedRun, 'awaiting_step_approval', [
                'status_message' => '后续步骤需要续批后继续执行。',
            ]);
        });
    }

    private function failRun(AiWorkspaceRun $run, string $code, string $message): void
    {
        $failed = $this->states->transitionLocked((string) $run->id, 'failed', [
            'failure_code' => $code,
            'failure_message' => $message,
            'status_message' => $message,
        ]);
        $this->realtime->broadcast($failed);
    }

    public function markJobFailure(string $runId, Throwable $exception, ?string $workerToken = null): void
    {
        $run = AiWorkspaceRun::query()->find($runId);
        if (! $run instanceof AiWorkspaceRun || $run->isTerminal()) {
            return;
        }
        if (! is_string($workerToken) || $workerToken === '') {
            $tokens = $run->steps()->where('state', 'running')->whereNotNull('lease_owner')->distinct()->pluck('lease_owner');
            if ($tokens->count() !== 1) {
                return;
            }
            $workerToken = (string) $tokens->first();
        }

        $message = AiWorkspaceErrorSanitizer::clean($exception->getMessage());
        if (in_array((string) $run->state, ['running', 'cancel_requested'], true)
            && $run->steps()->where('state', 'running')->where('lease_owner', $workerToken)->where('external_operation', true)->exists()) {
            $runningStep = $run->steps()->where('state', 'running')->where('lease_owner', $workerToken)->where('external_operation', true)->first();
            if ($runningStep instanceof AiWorkspaceStep) {
                $reconciled = $this->reconcileExpiredExternalStep($runningStep, false);
                if ($reconciled instanceof AiWorkspaceRun) {
                    $this->realtime->broadcast($reconciled);

                    return;
                }
                $recovered = $this->recoverPreparedExternalStep($runningStep, $workerToken, false);
                if ($recovered instanceof AiWorkspaceRun) {
                    $this->realtime->broadcast($recovered);

                    return;
                }
            }
            $unknown = DB::transaction(function () use ($runId, $message, $workerToken): ?AiWorkspaceRun {
                $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
                $updated = $locked->steps()->where('state', 'running')->where('lease_owner', $workerToken)->where('external_operation', true)->update([
                    'state' => 'outcome_unknown',
                    'error_message' => $message,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($updated === 0) {
                    return null;
                }

                return $this->states->transition($locked, 'outcome_unknown', [
                    'failure_code' => 'worker_failed_outcome_unknown',
                    'failure_message' => $message,
                    'status_message' => '执行器异常退出，外部结果需要人工对账。',
                ]);
            });
            if ($unknown instanceof AiWorkspaceRun) {
                $this->realtime->broadcast($unknown);
            }

            return;
        }

        if (in_array((string) $run->state, ['running', 'cancel_requested'], true)
            && $run->steps()->where('state', 'running')->where('lease_owner', $workerToken)->where('external_operation', false)->exists()) {
            $failed = DB::transaction(function () use ($runId, $message, $workerToken): ?AiWorkspaceRun {
                $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
                $cancelled = $locked->state === 'cancel_requested';
                $updated = $locked->steps()->where('state', 'running')->where('lease_owner', $workerToken)->where('external_operation', false)->update([
                    'state' => $cancelled ? 'skipped' : 'failed',
                    'error_message' => $cancelled ? '运行已取消，未完成的内部步骤已停止。' : $message,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($updated === 0) {
                    return null;
                }
                $terminal = $cancelled
                    ? ($locked->steps()->where('state', 'completed')->exists() ? 'partially_completed' : 'cancelled')
                    : ($locked->steps()->where('state', 'completed')->exists() ? 'partially_completed' : 'failed');

                return $this->states->transition($locked, $terminal, [
                    'failure_code' => $cancelled ? null : 'worker_failed',
                    'failure_message' => $cancelled ? null : $message,
                    'status_message' => $cancelled ? '运行已取消，内部步骤已安全停止。' : '执行器异常退出，内部步骤可安全重试。',
                ]);
            });
            if ($failed instanceof AiWorkspaceRun) {
                $this->realtime->broadcast($failed);
            }

            return;
        }
    }

    public function reconcileExpiredExternalStep(AiWorkspaceStep $step, bool $expiredOnly = true): ?AiWorkspaceRun
    {
        $step->refresh();
        if ($step->state !== 'running'
            || ! $step->external_operation
            || ($expiredOnly && $step->lease_expires_at?->isFuture())) {
            return null;
        }
        $run = $step->run()->first();
        if (! $run instanceof AiWorkspaceRun) {
            return null;
        }
        $result = $this->executor->reconcileRecordedExternal(
            (string) $step->capability_key,
            (array) $step->parameters,
            (string) $step->idempotency_key,
        );
        if (! $result instanceof AiCapabilityResult) {
            return null;
        }

        return DB::transaction(function () use ($run, $step, $result, $expiredOnly): ?AiWorkspaceRun {
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array((string) $lockedRun->state, ['running', 'cancel_requested'], true)
                || $lockedStep->state !== 'running'
                || ! $lockedStep->external_operation
                || ($expiredOnly && $lockedStep->lease_expires_at?->isFuture())) {
                return null;
            }
            $actor = Admin::query()->find($lockedRun->admin_id);
            $dataClassification = (string) data_get(
                $lockedRun->plan,
                'steps.'.max(0, (int) $lockedStep->position - 1).'.data_classification',
                'confidential',
            );
            $updated = $this->recordCompletedStep(
                $lockedRun,
                $lockedStep,
                $result,
                $dataClassification,
                $actor,
                (string) $lockedStep->lease_owner,
                true,
            );
            if ($lockedRun->state === 'cancel_requested') {
                return $this->states->transition($updated, 'partially_completed', [
                    'status_message' => '取消期间已确认外部结果，运行已停止。',
                ]);
            }
            $currentAdmin = $this->currentAdminForRun($lockedRun);
            $canContinue = false;
            if ($currentAdmin instanceof Admin) {
                try {
                    $currentCapability = $this->registry->get((string) $lockedStep->capability_key);
                    $canContinue = (bool) config('ai-workspace.runtime_enabled', false)
                        && $currentCapability->allows($currentAdmin)
                        && $currentCapability->isExecutable()
                        && hash_equals($currentCapability->version, (string) $lockedStep->capability_version);
                } catch (Throwable) {
                    $canContinue = false;
                }
            }
            if (! $canContinue) {
                $lockedRun->steps()->whereIn('state', ['pending', 'failed'])->update([
                    'state' => 'skipped',
                    'error_message' => '外部结果已确认，后续步骤因授权或能力版本变化而停止。',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->states->transition($updated, 'partially_completed', [
                    'failure_code' => 'governance_changed_after_external_result',
                    'failure_message' => '外部结果已确认，后续步骤因授权或能力版本变化而停止。',
                    'status_message' => '已保留确认的外部结果，并停止后续步骤。',
                ]);
            }
            $queued = $this->states->transition($updated, 'queued', [
                'status_message' => '外部步骤已完成对账，等待继续执行。',
            ]);
            DB::afterCommit(fn () => $this->dispatch($queued));

            return $queued;
        });
    }

    public function recoverPreparedExternalStep(AiWorkspaceStep $step, ?string $leaseOwner = null, bool $expiredOnly = true): ?AiWorkspaceRun
    {
        return DB::transaction(function () use ($step, $leaseOwner, $expiredOnly): ?AiWorkspaceRun {
            $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($lockedStep->run_id);
            if ($lockedStep->state !== 'running'
                || ! $lockedStep->external_operation
                || ! in_array((string) $lockedRun->state, ['running', 'cancel_requested'], true)
                || ($expiredOnly && $lockedStep->lease_expires_at?->isFuture())
                || (is_string($leaseOwner) && $leaseOwner !== '' && ! hash_equals((string) $lockedStep->lease_owner, $leaseOwner))) {
                return null;
            }
            $statuses = $lockedStep->externalOperations()->lockForUpdate()->pluck('status');
            $atomicDistributionEnqueue = $lockedStep->capability_key === 'distribution.publish' && $statuses->isEmpty();
            $preparedLedger = $statuses->contains('prepared')
                && $statuses->every(static fn (string $status): bool => in_array($status, ['prepared', 'confirmed'], true));
            if (! $atomicDistributionEnqueue && ! $preparedLedger) {
                return null;
            }
            if ($lockedRun->state === 'cancel_requested') {
                $lockedStep->forceFill([
                    'state' => 'skipped',
                    'error_message' => '运行已取消，尚未发出的外部请求已停止。',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                ])->save();
                $hasCompletedOutcome = $lockedRun->steps()->where('state', 'completed')->exists()
                    || $lockedStep->externalOperations()->where('status', 'confirmed')->exists();

                return $this->states->transition($lockedRun, $hasCompletedOutcome ? 'partially_completed' : 'cancelled', [
                    'status_message' => '运行已取消，尚未发出的外部请求已停止。',
                ]);
            }
            $lockedStep->forceFill([
                'state' => 'pending',
                'attempts' => max(0, (int) $lockedStep->attempts - 1),
                'error_message' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'started_at' => null,
                'finished_at' => null,
            ])->save();
            $queued = $this->states->transition($lockedRun, 'queued', [
                'status_message' => '外部请求尚未发出，已恢复到安全队列。',
            ]);
            DB::afterCommit(fn () => $this->dispatch($queued));

            return $queued;
        });
    }

    private function recordCompletedStep(
        AiWorkspaceRun $run,
        AiWorkspaceStep $step,
        AiCapabilityResult $result,
        string $dataClassification,
        ?Admin $admin,
        string $leaseOwner,
        bool $allowExpiredLease = false,
    ): AiWorkspaceRun {
        $expectedType = (string) (($step->result_contract ?? [])['type'] ?? '');
        if ($expectedType === '' || ! hash_equals($expectedType, $result->artifactType)) {
            throw new RuntimeException('能力执行结果不符合已登记契约。');
        }
        $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
        if ($lockedStep->state !== 'running'
            || ! hash_equals((string) $lockedStep->lease_owner, $leaseOwner)
            || (! $allowExpiredLease && ! $lockedStep->lease_expires_at?->isFuture())) {
            throw new RuntimeException('步骤执行租约已经失效，结果不会写入。');
        }
        $lockedStep->forceFill([
            'state' => 'completed',
            'result_summary' => $result->toArray(),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();
        AiWorkspaceArtifact::query()->firstOrCreate(
            ['step_id' => $lockedStep->id],
            [
                'id' => (string) Str::uuid7(),
                'run_id' => $run->id,
                'created_by_admin_id' => $admin?->id,
                'created_by_username_snapshot' => $admin?->username ?: $run->admin_username_snapshot,
                'type' => $result->artifactType,
                'name' => $result->artifactName,
                'data_classification' => $dataClassification,
                'content' => $result->summary,
                'payload' => $result->payload,
                'source_route' => $result->sourceRoute,
                'source_url' => $result->sourceUrl,
                'expires_at' => now()->addDays((int) config('ai-workspace.retention_days', 90)),
            ],
        );
        $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);

        return $this->states->touchEvent($lockedRun, [
            'system_operations_executed' => true,
            'status_message' => '步骤已完成，正在继续执行。',
        ]);
    }

    private function reconcileClaimedExternalStep(
        AiWorkspaceRun $run,
        AiWorkspaceStep $step,
        Admin $admin,
        string $leaseOwner,
    ): ?AiWorkspaceRun {
        $result = $this->executor->reconcileExternal(
            (string) $step->capability_key,
            (array) $step->parameters,
            $admin,
            (string) $step->idempotency_key,
        );
        if (! $result instanceof AiCapabilityResult) {
            return null;
        }
        $capability = $this->registry->get((string) $step->capability_key);

        return DB::transaction(fn (): AiWorkspaceRun => $this->recordCompletedStep(
            $run,
            $step,
            $result,
            $capability->dataClassification,
            $admin,
            $leaseOwner,
        ));
    }

    private function dispatch(AiWorkspaceRun $run): void
    {
        try {
            ProcessAiWorkspaceRunJob::dispatch((string) $run->id)->onQueue((string) config('ai-workspace.queue', 'ai-workspace'));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function defer(string $runId): void
    {
        try {
            ProcessAiWorkspaceRunJob::dispatch($runId)
                ->onQueue((string) config('ai-workspace.queue', 'ai-workspace'))
                ->delay(now()->addSeconds(5));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function assertOwner(Admin $admin, AiWorkspaceRun $run): void
    {
        if ((int) $run->admin_id !== (int) $admin->id) {
            throw (new ModelNotFoundException)->setModel(AiWorkspaceRun::class, [$run->id]);
        }
    }

    private function currentAdminForRun(AiWorkspaceRun $run): ?Admin
    {
        $admin = Admin::query()->whereKey($run->admin_id)->where('status', 'active')->first();
        if (! $admin instanceof Admin) {
            return null;
        }
        if ($run->admin_auth_version !== null
            && (int) $run->admin_auth_version !== (int) $admin->auth_version) {
            return null;
        }

        return $admin;
    }

    private function looksLikeUnknownOutcome(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage(), 'UTF-8');

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, '超时')
            || str_contains($message, 'connection reset');
    }

    private function reviveSkippedDependents(AiWorkspaceRun $run, int $rootPosition): void
    {
        $positions = [$rootPosition];
        $steps = $run->steps()->orderBy('position')->lockForUpdate()->get();
        do {
            $added = false;
            foreach ($steps as $step) {
                if ($step->state !== 'skipped' || in_array((int) $step->position, $positions, true)) {
                    continue;
                }
                if (array_intersect($positions, array_map('intval', (array) $step->depends_on)) === []) {
                    continue;
                }
                $positions[] = (int) $step->position;
                $step->forceFill([
                    'state' => 'pending',
                    'error_message' => null,
                    'result_summary' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ])->save();
                $added = true;
            }
        } while ($added);
    }

    public function stopForDisabledRuntime(string $runId): ?AiWorkspaceRun
    {
        $stopped = DB::transaction(function () use ($runId): ?AiWorkspaceRun {
            $run = AiWorkspaceRun::query()->lockForUpdate()->find($runId);
            if (! $run instanceof AiWorkspaceRun || $run->isTerminal()) {
                return null;
            }
            $run->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decision_reason' => 'AI 工作台运行时已关闭',
                'decided_at' => now(),
            ]);
            $hasRunningStep = $run->steps()
                ->where('state', 'running')
                ->exists();
            $run->steps()->whereIn('state', $hasRunningStep ? ['pending', 'failed'] : ['pending', 'running', 'failed'])->update([
                'state' => 'skipped',
                'error_message' => $hasRunningStep
                    ? '运行时已关闭，未开始的后续步骤已停止。'
                    : '运行时已关闭，步骤已安全停止。',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            if ($hasRunningStep) {
                if ($run->state === 'cancel_requested') {
                    return $this->states->touchEvent($run, [
                        'cancel_requested_at' => $run->cancel_requested_at ?? now(),
                        'status_message' => '运行时已关闭，正在等待执行中步骤收口。',
                    ]);
                }

                return $this->states->transition($run, 'cancel_requested', [
                    'cancel_requested_at' => now(),
                    'resolution_lease_owner' => null,
                    'resolution_lease_expires_at' => null,
                    'status_message' => '运行时已关闭，正在等待执行中步骤收口。',
                ]);
            }

            $targetState = $run->steps()->where('state', 'completed')->exists() ? 'partially_completed' : 'cancelled';

            return $this->states->transition($run, $targetState, [
                'cancel_requested_at' => now(),
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
                'status_message' => '运行时已关闭，工作流已停止。',
            ]);
        });
        if ($stopped instanceof AiWorkspaceRun) {
            $this->realtime->broadcast($stopped);
        }

        return $stopped;
    }
}
