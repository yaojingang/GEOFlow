<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiCapabilityResult;
use App\Ai\Workspace\AiOutcomeUnknownException;
use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiPlanCompiler;
use App\Ai\Workspace\AiWorkflowPlan;
use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
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
        private AiWorkspaceExecutionAccessGuard $executionGuard,
    ) {}

    public function prepare(
        AiWorkspaceRun $run,
        AiWorkflowPlan $plan,
        ?string $resolutionLeaseOwner = null,
        ?AiWorkspaceModelExecutionReceipt $modelReceipt = null,
    ): AiWorkspaceRun {
        $run = DB::transaction(function () use ($run, $plan, $resolutionLeaseOwner, $modelReceipt): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($modelReceipt instanceof AiWorkspaceModelExecutionReceipt && is_string($resolutionLeaseOwner)) {
                $context = $this->executionGuard->contextFromResolutionRun($locked, $resolutionLeaseOwner);
                $this->executionGuard->assertReceiptCurrent($context, $modelReceipt);
            }
            if (! $this->currentAdminForRun($locked) instanceof Admin) {
                throw new RuntimeException('管理员授权已变化，不能写入执行计划。');
            }
            if (is_string($resolutionLeaseOwner)
                && (! hash_equals((string) $locked->resolution_lease_owner, $resolutionLeaseOwner)
                    || ! $locked->resolution_lease_expires_at?->isFuture())) {
                throw new RuntimeException('请求理解租约已经失效。');
            }
            if (! in_array((string) $locked->state, ['planning', 'validating_plan'], true)) {
                throw new RuntimeException('运行状态不允许写入计划。');
            }
            if ($locked->mode === 'agent_child') {
                $allowed = array_keys((array) $locked->capability_versions);
                $planned = collect($plan->steps)
                    ->map(static fn (array $step): string => (string) ($step['capability'] ?? ''))
                    ->filter()
                    ->values()
                    ->all();
                if (count($allowed) !== 1
                    || count($planned) !== 1
                    || ! hash_equals((string) $allowed[0], (string) $planned[0])) {
                    throw new RuntimeException('只读子任务计划超出父任务授权范围。');
                }
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

            $expiredApprovalCount = $locked->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decided_at' => now(),
                'decision_reason' => '计划版本已变更',
            ]);
            if ($expiredApprovalCount > 0) {
                $locked = $this->states->touchEvent($locked, [], [
                    'event_type' => 'approval.expired',
                    'kind' => 'approval',
                    'title' => '旧审批已失效',
                    'summary' => '计划版本变化后，旧审批已统一失效。',
                    'status' => 'completed',
                    'payload' => ['usage' => ['approvals' => $expiredApprovalCount]],
                ]);
            }
            $locked->approvals()->whereNotNull('step_id')->update(['step_id' => null]);
            $locked->steps()->delete();
            foreach ($plan->steps as $step) {
                $createdStep = $locked->steps()->create([
                    'id' => (string) Str::uuid7(),
                    'position' => (int) $step['position'],
                    'capability_key' => (string) $step['capability'],
                    'capability_name' => (string) $step['capability_name'],
                    'capability_version' => (string) $step['capability_version'],
                    'state' => 'pending',
                    'queued_at' => now(),
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
                $locked = $this->states->touchEvent($locked, [], $this->stepEvent(
                    $createdStep,
                    'step.queued',
                    'running',
                    '步骤已写入受控计划并等待执行。',
                ));
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
            $this->assertCurrentAuthority($admin, $run);
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
                return $this->states->touchEvent($run, ['status_message' => '已确认一项，请继续审批剩余操作。'], [
                    'event_type' => 'approval.approved',
                    'kind' => 'approval',
                    'title' => '审批已确认',
                    'summary' => '一项审批已确认，仍有待确认项。',
                    'status' => 'completed',
                ]);
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

            $run = $this->states->touchEvent($run, [], [
                'event_type' => 'approval.approved',
                'kind' => 'approval',
                'title' => '审批已确认',
                'summary' => '计划所需审批已全部确认。',
                'status' => 'completed',
            ]);
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
            $this->assertCurrentAuthority($admin, $run);
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

            $run = $this->states->touchEvent($run, [], [
                'event_type' => 'approval.rejected',
                'kind' => 'approval',
                'title' => '审批已拒绝',
                'summary' => '当前计划已被拒绝。',
                'status' => 'failed',
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
            $this->assertCurrentAuthority($admin, $locked);
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
            $planning = $this->states->transition($locked, 'planning', ['status_message' => '正在校验修改后的计划。'], [
                'event_type' => 'plan.revised',
                'kind' => 'plan',
                'title' => '计划已修改',
                'summary' => '管理员已提交新的计划版本，旧审批将失效。',
                'status' => 'completed',
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
            ]);

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
            $expiredApprovalCount = $locked->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decision_reason' => '运行已取消',
                'decided_at' => now(),
            ]);
            if ($expiredApprovalCount > 0) {
                $locked = $this->states->touchEvent($locked, [], [
                    'event_type' => 'approval.expired',
                    'kind' => 'approval',
                    'title' => '审批已失效',
                    'summary' => '运行取消后，待处理审批已统一失效。',
                    'status' => 'completed',
                    'payload' => ['usage' => ['approvals' => $expiredApprovalCount]],
                ]);
            }
            $state = $locked->state === 'running'
                || ($locked->state === 'answering' && $locked->resolution_lease_owner !== null)
                    ? 'cancel_requested'
                    : 'cancelled';

            $locked = $this->states->transition($locked, $state, [
                'cancel_requested_at' => now(),
                'status_message' => $state === 'cancelled' ? '运行已取消。' : '已请求取消，正在等待当前步骤结束。',
            ]);
            if ($locked->mode === 'multi_agent') {
                $children = $locked->childRuns()
                    ->whereNotIn('state', AiWorkspaceRun::TERMINAL_STATES)
                    ->lockForUpdate()
                    ->get();
                foreach ($children as $child) {
                    $childExpiredApprovalCount = $child->approvals()->where('status', 'pending')->update([
                        'status' => 'expired',
                        'decision_reason' => '父任务已取消',
                        'decided_at' => now(),
                    ]);
                    if ($childExpiredApprovalCount > 0) {
                        $child = $this->states->touchEvent($child, [], [
                            'event_type' => 'approval.expired',
                            'kind' => 'approval',
                            'title' => '审批已失效',
                            'summary' => '父任务取消后，子任务待处理审批已失效。',
                            'status' => 'stopped',
                            'payload' => ['usage' => ['approvals' => $childExpiredApprovalCount]],
                        ]);
                    }
                    $childState = $child->state === 'running' ? 'cancel_requested' : 'cancelled';
                    $this->states->transition($child, $childState, [
                        'cancel_requested_at' => now(),
                        'status_message' => '父任务已请求停止，只读子任务正在收口。',
                    ]);
                }
            }

            return $locked;
        });
        $this->realtime->broadcast($cancelled);

        return $cancelled;
    }

    public function retryStep(Admin $admin, AiWorkspaceStep $step): AiWorkspaceRun
    {
        $run = $step->run()->firstOrFail();
        $this->assertCurrentAuthority($admin, $run);
        if ($run->payload_pruned_at !== null) {
            throw new RuntimeException('运行载荷已按留存策略清理，不能再重试。');
        }
        if (! (bool) $run->retryable_failure) {
            throw new RuntimeException('该运行包含永久失败，不能重新入队。');
        }
        if (! in_array((string) $run->state, ['failed', 'partially_completed'], true)) {
            throw new RuntimeException('运行尚未结束，不能同时启动重试。');
        }
        if ($step->state !== 'failed' || (bool) $step->external_operation || $step->attempts >= $step->max_attempts) {
            throw new RuntimeException('该步骤不允许自动重试。');
        }
        DB::transaction(function () use ($admin, $step, $run): void {
            $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertCurrentAuthority($admin, $lockedRun);
            if (! in_array((string) $lockedRun->state, ['failed', 'partially_completed'], true)) {
                throw new RuntimeException('运行重试状态已经变化。');
            }
            if ($lockedRun->payload_pruned_at !== null) {
                throw new RuntimeException('运行载荷已按留存策略清理，不能再重试。');
            }
            if (! (bool) $lockedRun->retryable_failure) {
                throw new RuntimeException('该运行包含永久失败，不能重新入队。');
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
            $claim = DB::transaction(function () use ($runId, $workerToken): array {
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
                        'execution_lease_token' => $workerToken,
                        'execution_lease_expires_at' => now()->addMinutes(
                            max(1, (int) config('ai-workspace.execution_lease_minutes', 15)),
                        ),
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

        $admin = $this->currentAdminForRun($run, $workerToken);
        if (! $admin instanceof Admin) {
            $this->failRun($run, 'authorization_revoked', '管理员已停用、不存在或授权版本已变化。', $workerToken);

            return;
        }

        foreach ($run->steps()->orderBy('position')->get() as $step) {
            $run->refresh();
            $admin = $this->currentAdminForRun($run, $workerToken);
            if (! $admin instanceof Admin) {
                $this->failRun($run, 'authorization_revoked', '管理员授权已变化，已停止后续步骤。', $workerToken);

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
                $this->renewExecutionLease((string) $run->id, $workerToken);
                $run = $this->states->touchEventLocked(
                    (string) $run->id,
                    ['status_message' => '已领取“'.($step->capability_name ?: $step->capability_key).'”执行租约。'],
                    $this->stepEvent($step, 'step.started', 'running', '步骤执行租约已领取。'),
                );
                $run = $this->states->touchEventLocked(
                    (string) $run->id,
                    ['status_message' => '正在执行“'.($step->capability_name ?: $step->capability_key).'”。'],
                    $this->traceForStep($step, 'running', '正在调用已登记能力。'),
                );
                $this->realtime->broadcast($run);
                if (str_starts_with((string) $step->execution_scope, 'external_')) {
                    if ($step->capability_key === 'distribution.publish') {
                        $run = DB::transaction(function () use ($run, $step, $capability, $leaseOwner): AiWorkspaceRun {
                            [$lockedRun, $lockedStep, $authorizedAdmin] = $this->lockAuthorizedClaimedStep($run, $step, $leaseOwner);
                            $this->compiler->lockTargetsFor((string) $lockedStep->capability_key, (array) $lockedStep->parameters);
                            $this->assertTargetUnchanged($lockedStep);
                            $lockedRun = $this->recordExternalEvent($lockedRun, $lockedStep, 'external.prepared', '外部操作已准备', '目标与审批摘要已锁定，外部操作已进入受控准备阶段。', 'completed');
                            $result = $this->executor->execute($lockedStep->capability_key, $lockedStep->parameters, $authorizedAdmin, (string) $lockedStep->idempotency_key);

                            return $this->recordCompletedStep($lockedRun, $lockedStep, $result, $capability->dataClassification, $authorizedAdmin, $leaseOwner);
                        });
                    } else {
                        $run = DB::transaction(function () use ($run, $step, $leaseOwner): AiWorkspaceRun {
                            [$lockedRun, $lockedStep] = $this->lockAuthorizedClaimedStep($run, $step, $leaseOwner);
                            $this->compiler->lockTargetsFor((string) $lockedStep->capability_key, (array) $lockedStep->parameters);
                            $this->assertTargetUnchanged($lockedStep);
                            $this->executor->prepareExternalExecution($lockedStep);

                            return $this->recordExternalEvent($lockedRun, $lockedStep, 'external.prepared', '外部操作已准备', '外部操作账本已建立并完成目标摘要校验。', 'completed');
                        });
                        $admin = $this->currentAdminForRun($run, $workerToken);
                        if (! $admin instanceof Admin || ! $capability->allows($admin)) {
                            throw new RuntimeException('外部请求发出前授权已变化。');
                        }
                        $result = $this->executor->execute($step->capability_key, $step->parameters, $admin, (string) $step->idempotency_key);
                        $run = DB::transaction(fn (): AiWorkspaceRun => $this->recordCompletedStep($run, $step, $result, $capability->dataClassification, $admin, $leaseOwner));
                    }
                } else {
                    // Internal mutations and their execution record commit atomically.
                    $run = DB::transaction(function () use ($run, $step, $capability, $leaseOwner): AiWorkspaceRun {
                        [$lockedRun, $lockedStep, $authorizedAdmin] = $this->lockAuthorizedClaimedStep($run, $step, $leaseOwner);
                        $this->compiler->lockTargetsFor((string) $lockedStep->capability_key, (array) $lockedStep->parameters);
                        $this->assertTargetUnchanged($lockedStep);
                        $result = $this->executor->execute($lockedStep->capability_key, $lockedStep->parameters, $authorizedAdmin, (string) $lockedStep->idempotency_key);

                        return $this->recordCompletedStep($lockedRun, $lockedStep, $result, $capability->dataClassification, $authorizedAdmin, $leaseOwner);
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
            $this->failRun($run, 'dependency_deadlock', '工作流依赖无法继续，请重新生成计划。', $workerToken);

            return;
        }
        $terminal = $hasFailures
            ? ($completedCount > 0 ? 'partially_completed' : 'failed')
            : 'completed';
        $summary = $run->artifacts()->where('type', '!=', 'plan_revision')->orderBy('created_at')->pluck('content')->filter()->implode("\n");
        $run = DB::transaction(function () use ($run, $terminal, $summary, $hasFailures, $completedCount, $admin, $workerToken): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertExecutionLease($locked, $workerToken);
            $this->executionGuard->assertCurrent(
                $this->executionGuard->contextFromExecutionRun($locked, $workerToken),
            );
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
            $this->conversations->saveRunResponse($conversation, (string) $completed->id, $summary, [
                'system_operations_executed' => $completedCount > 0,
            ]);

            return $completed;
        });
        $this->realtime->broadcast($run);
    }

    public function recoverExpiredExecutions(int $limit = 50): int
    {
        $ids = AiWorkspaceRun::query()
            ->whereIn('state', ['running', 'cancel_requested'])
            ->where(function ($query): void {
                $query->whereNull('execution_lease_expires_at')
                    ->orWhere('execution_lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');
        $recoveredCount = 0;

        foreach ($ids as $id) {
            $run = AiWorkspaceRun::query()->find($id);
            if (! $run instanceof AiWorkspaceRun) {
                continue;
            }
            $externalStep = $run->steps()
                ->where('state', 'running')
                ->where('external_operation', true)
                ->first();
            if ($externalStep instanceof AiWorkspaceStep) {
                $recovered = $this->reconcileExpiredExternalStep($externalStep)
                    ?? $this->recoverPreparedExternalStep($externalStep);
                if (! $recovered instanceof AiWorkspaceRun) {
                    $recovered = DB::transaction(function () use ($id, $externalStep): ?AiWorkspaceRun {
                        $lockedRun = AiWorkspaceRun::query()->whereKey($id)->lockForUpdate()->first();
                        $lockedStep = AiWorkspaceStep::query()->whereKey($externalStep->id)->lockForUpdate()->first();
                        if (! $lockedRun instanceof AiWorkspaceRun
                            || ! $lockedStep instanceof AiWorkspaceStep
                            || ! in_array((string) $lockedRun->state, ['running', 'cancel_requested'], true)
                            || $lockedRun->execution_lease_expires_at?->isFuture()
                            || (string) $lockedStep->state !== 'running') {
                            return null;
                        }
                        $lockedStep->forceFill([
                            'state' => 'outcome_unknown',
                            'error_message' => '过期执行租约包含未确认的外部操作。',
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'finished_at' => now(),
                        ])->save();

                        return $this->states->transition($lockedRun, 'outcome_unknown', [
                            'failure_code' => 'outcome_unknown',
                            'failure_message' => '过期执行租约包含未确认的外部操作。',
                            'status_message' => '外部操作结果需要人工确认。',
                            'retryable_failure' => false,
                        ]);
                    }, 3);
                }
            } else {
                $recovered = DB::transaction(function () use ($id): ?AiWorkspaceRun {
                    $locked = AiWorkspaceRun::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $locked instanceof AiWorkspaceRun
                        || ! in_array((string) $locked->state, ['running', 'cancel_requested'], true)
                        || $locked->execution_lease_expires_at?->isFuture()) {
                        return null;
                    }
                    if (! $this->executionGuard->identityComplete($locked)) {
                        return $this->states->transition($locked, 'failed', [
                            'failure_code' => 'authorization_revoked',
                            'failure_message' => 'AI 工作台历史运行缺少完整执行身份。',
                            'status_message' => 'AI 工作台历史运行缺少完整执行身份。',
                            'retryable_failure' => false,
                        ]);
                    }
                    if ((string) $locked->state === 'cancel_requested') {
                        $locked->steps()->whereIn('state', ['pending', 'running'])->update([
                            'state' => 'skipped',
                            'error_message' => '运行取消后已由恢复任务收口。',
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'finished_at' => now(),
                        ]);
                        $terminal = $locked->steps()->where('state', 'completed')->exists()
                            ? 'partially_completed'
                            : 'cancelled';

                        return $this->states->transition($locked, $terminal, [
                            'status_message' => '过期运行已按取消请求收口。',
                        ]);
                    }

                    $locked->steps()
                        ->where('state', 'running')
                        ->where('external_operation', false)
                        ->update([
                            'state' => 'pending',
                            'error_message' => null,
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'started_at' => null,
                            'finished_at' => null,
                        ]);
                    $queued = $this->states->transition($locked, 'queued', [
                        'status_message' => '过期执行租约已轮换并重新入队。',
                    ]);
                    DB::afterCommit(fn () => $this->dispatch($queued));

                    return $queued;
                }, 3);
            }

            if ($recovered instanceof AiWorkspaceRun) {
                $recoveredCount++;
                $this->realtime->broadcast($recovered);
            }
        }

        return $recoveredCount;
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

            $lockedRun = $this->states->touchEvent(
                $lockedRun,
                ['status_message' => '已跳过依赖失败的步骤，继续处理独立分支。'],
                $this->traceForStep($lockedStep, 'attention', '依赖步骤未成功，当前分支已跳过。'),
            );
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
            $expiredApprovalCount = $lockedRun->approvals()->whereIn('status', ['pending', 'approved'])->update([
                'status' => 'expired',
                'decision_reason' => '前序产物已绑定，参数摘要发生变化',
                'decided_at' => now(),
            ]);
            $lockedRun = $this->states->touchEvent($lockedRun, [
                'plan' => $plan,
                'plan_digest' => $planDigest,
                'parameter_digest' => $parameterDigest,
                'target_digest' => $targetDigest,
                'status_message' => '已绑定前序结果，正在校验后续步骤。',
            ], [
                'event_type' => 'plan.revised',
                'kind' => 'plan',
                'title' => '计划输入已绑定',
                'summary' => '前序持久化产物已绑定到后续步骤，计划摘要已更新。',
                'status' => 'completed',
            ]);
            if ($expiredApprovalCount > 0) {
                $this->states->touchEvent($lockedRun, [], [
                    'event_type' => 'approval.expired',
                    'kind' => 'approval',
                    'title' => '审批已失效',
                    'summary' => '计划输入变化后，原审批已失效。',
                    'status' => 'attention',
                    'payload' => ['usage' => ['approvals' => $expiredApprovalCount]],
                ]);
            }

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

            $lockedRun = $this->states->touchEvent(
                $lockedRun,
                [
                    'failure_code' => 'step_failed',
                    'failure_message' => $message,
                    'status_message' => '步骤执行失败，正在检查独立分支。',
                ],
                $this->traceForStep($lockedStep, 'failed', $message),
            );

            return $this->states->touchEvent($lockedRun, [], $this->stepEvent(
                $lockedStep,
                'step.failed',
                'failed',
                $message,
            ));
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

            return $this->states->touchEvent(
                $lockedRun,
                [
                    'failure_code' => 'step_validation_failed',
                    'failure_message' => $message,
                    'status_message' => '步骤校验失败，正在检查独立分支。',
                ],
                $this->stepEvent($lockedStep, 'step.failed', 'failed', $message),
            );
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

            $lockedRun = $this->states->touchEvent(
                $lockedRun,
                ['status_message' => '外部结果无法确认，正在收口运行。'],
                $this->traceForStep($lockedStep, 'failed', '能力调用未取得可确认结果。'),
            );
            $lockedRun = $this->states->touchEvent($lockedRun, [], $this->stepEvent(
                $lockedStep,
                'step.failed',
                'failed',
                '外部步骤未取得可确认结果。',
            ));
            $lockedRun = $this->recordExternalEvent(
                $lockedRun,
                $lockedStep,
                'external.outcome_unknown',
                '外部结果待确认',
                '外部结果无法安全确认，已停止自动重试。',
                'attention',
            );

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
                'queued_at' => $step->queued_at ?? now(),
                'started_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('步骤已由其他执行器领取。');
        }
        $step->refresh();

        return $leaseOwner;
    }

    private function renewExecutionLease(string $runId, string $workerToken): void
    {
        $leaseMinutes = max(
            1,
            (int) config('ai-workspace.execution_lease_minutes', 15),
            (int) config('ai-workspace.step_lease_minutes', 20),
        );
        $updated = AiWorkspaceRun::query()
            ->whereKey($runId)
            ->where('state', 'running')
            ->where('execution_lease_token', $workerToken)
            ->where('execution_lease_expires_at', '>', now())
            ->update([
                'execution_lease_expires_at' => now()->addMinutes($leaseMinutes),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('AI 工作台执行租约已经失效。');
        }
    }

    /** @return array<string,mixed> */
    private function traceForStep(AiWorkspaceStep $step, string $status, string $summary): array
    {
        $eventType = match ($status) {
            'completed' => 'capability.call_completed',
            'failed' => 'capability.call_failed',
            'attention' => $step->state === 'outcome_unknown' ? 'external.outcome_unknown' : 'step.skipped',
            default => 'capability.call_started',
        };

        return [
            'event_type' => $eventType,
            'kind' => 'tool',
            'title' => (string) ($step->capability_name ?: $step->capability_key),
            'summary' => $summary,
            'status' => $status,
            'step_id' => (string) $step->id,
            'detail' => [
                'capability' => (string) $step->capability_key,
                'capability_name' => (string) ($step->capability_name ?: $step->capability_key),
                'position' => (int) $step->position,
                'risk_level' => (string) $step->risk_level,
                'execution_scope' => (string) $step->execution_scope,
                'approval_policy' => (string) $step->approval_policy,
                'attempts' => (int) $step->attempts,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function stepEvent(AiWorkspaceStep $step, string $eventType, string $status, string $summary): array
    {
        return [
            'event_type' => $eventType,
            'kind' => 'step',
            'title' => (string) ($step->capability_name ?: $step->capability_key),
            'summary' => $summary,
            'status' => $status,
            'step_id' => (string) $step->id,
            'detail' => $this->traceForStep($step, $status, '')['detail'],
            'payload' => [
                'capability' => (string) $step->capability_key,
                'capability_version' => (string) $step->capability_version,
                'position' => (int) $step->position,
            ],
        ];
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
            $expiredApprovalCount = $lockedRun->approvals()
                ->whereIn('status', ['pending', 'approved'])
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => 'expired',
                    'decision_reason' => '审批已过期，等待续批',
                    'decided_at' => now(),
                ]);
            if ($expiredApprovalCount > 0) {
                $lockedRun = $this->states->touchEvent($lockedRun, [], [
                    'event_type' => 'approval.expired',
                    'kind' => 'approval',
                    'title' => '审批已过期',
                    'summary' => '已过期审批已关闭，后续步骤需要重新确认。',
                    'status' => 'attention',
                    'payload' => ['usage' => ['approvals' => $expiredApprovalCount]],
                ]);
            }
            $groupedCapabilities = [];
            $createdApprovalCount = 0;
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
                    $createdApprovalCount++;
                }
            }

            return $this->states->transition(
                $lockedRun,
                'awaiting_step_approval',
                ['status_message' => '后续步骤需要续批后继续执行。'],
                [
                    'event_type' => 'approval.requested',
                    'kind' => 'approval',
                    'title' => (string) ($lockedStep->capability_name ?: $lockedStep->capability_key),
                    'summary' => '后续操作需要确认后继续。',
                    'status' => 'attention',
                    'step_id' => (string) $lockedStep->id,
                    'detail' => $this->traceForStep($lockedStep, 'attention', '')['detail'],
                    'payload' => ['usage' => ['approvals' => $createdApprovalCount]],
                ],
            );
        });
    }

    private function failRun(AiWorkspaceRun $run, string $code, string $message, ?string $workerToken = null): void
    {
        $failed = DB::transaction(function () use ($run, $code, $message, $workerToken): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (is_string($workerToken) && $workerToken !== '') {
                $this->assertExecutionLease($locked, $workerToken);
            }
            if ($code === 'authorization_revoked') {
                $locked = $this->states->touchEvent($locked, [], [
                    'event_type' => 'authorization.revoked',
                    'kind' => 'guard',
                    'title' => '授权已撤销',
                    'summary' => $message,
                    'status' => 'failed',
                    'payload' => ['failure_code' => $code],
                ]);
            }

            return $this->states->transition($locked, 'failed', [
                'failure_code' => $code,
                'failure_message' => $message,
                'status_message' => $message,
                'retryable_failure' => $code !== 'authorization_revoked',
            ]);
        });
        $failed = $this->finalizeTerminalResponse($failed);
        $this->realtime->broadcast($failed);
    }

    public function finalizeTerminalResponse(AiWorkspaceRun $run): AiWorkspaceRun
    {
        return DB::transaction(function () use ($run): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! $locked->isTerminal()) {
                return $locked;
            }
            $summary = $locked->artifacts()
                ->where('type', '!=', 'plan_revision')
                ->orderBy('created_at')
                ->pluck('content')
                ->filter(static fn ($content): bool => trim((string) $content) !== '')
                ->implode("\n");
            if ($summary === '') {
                return $locked;
            }
            $completedCount = $locked->steps()->where('state', 'completed')->count();
            $locked->forceFill([
                'answer' => $summary,
                'answer_is_partial' => $locked->state !== 'completed',
                'system_operations_executed' => $completedCount > 0,
            ])->save();
            $conversation = $locked->conversation()->firstOrFail();
            $this->conversations->saveRunResponse($conversation, (string) $locked->id, $summary, [
                'system_operations_executed' => $completedCount > 0,
                'state' => (string) $locked->state,
                'incomplete' => $locked->state !== 'completed',
            ]);

            return $locked->refresh();
        });
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
        if (! hash_equals(trim((string) $run->execution_lease_token), $workerToken)) {
            return;
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
                $lockedStep = $locked->steps()
                    ->where('state', 'running')
                    ->where('lease_owner', $workerToken)
                    ->where('external_operation', true)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedStep instanceof AiWorkspaceStep) {
                    return null;
                }
                $lockedStep->forceFill([
                    'state' => 'outcome_unknown',
                    'error_message' => $message,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                ])->save();
                $locked = $this->states->touchEvent($locked, [], [
                    'event_type' => 'external.outcome_unknown',
                    'kind' => 'guard',
                    'title' => (string) ($lockedStep->capability_name ?: $lockedStep->capability_key),
                    'summary' => '执行器异常退出，外部操作结果需要人工对账。',
                    'status' => 'attention',
                    'step_id' => (string) $lockedStep->id,
                    'detail' => $this->traceForStep($lockedStep, 'attention', '')['detail'],
                    'payload' => ['failure_code' => 'worker_failed_outcome_unknown'],
                ]);
                $locked = $this->states->touchEvent($locked, [], [
                    'event_type' => 'capability.call_failed',
                    'kind' => 'tool',
                    'title' => (string) ($lockedStep->capability_name ?: $lockedStep->capability_key),
                    'summary' => '能力调用未能确认最终结果。',
                    'status' => 'failed',
                    'step_id' => (string) $lockedStep->id,
                    'detail' => $this->traceForStep($lockedStep, 'failed', '')['detail'],
                    'payload' => ['failure_code' => 'worker_failed_outcome_unknown'],
                ]);

                return $this->states->transition($locked, 'outcome_unknown', [
                    'failure_code' => 'worker_failed_outcome_unknown',
                    'failure_message' => $message,
                    'status_message' => '执行器异常退出，外部结果需要人工对账。',
                ]);
            });
            if ($unknown instanceof AiWorkspaceRun) {
                $unknown = $this->finalizeTerminalResponse($unknown);
                $this->realtime->broadcast($unknown);
            }

            return;
        }

        if (in_array((string) $run->state, ['running', 'cancel_requested'], true)
            && $run->steps()->where('state', 'running')->where('lease_owner', $workerToken)->where('external_operation', false)->exists()) {
            $failed = DB::transaction(function () use ($runId, $message, $workerToken): ?AiWorkspaceRun {
                $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
                $cancelled = $locked->state === 'cancel_requested';
                $lockedStep = $locked->steps()
                    ->where('state', 'running')
                    ->where('lease_owner', $workerToken)
                    ->where('external_operation', false)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedStep instanceof AiWorkspaceStep) {
                    return null;
                }
                $lockedStep->forceFill([
                    'state' => $cancelled ? 'skipped' : 'failed',
                    'error_message' => $cancelled ? '运行已取消，未完成的内部步骤已停止。' : $message,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                ])->save();
                if (! $cancelled) {
                    $locked = $this->states->touchEvent($locked, [], [
                        'event_type' => 'capability.call_failed',
                        'kind' => 'tool',
                        'title' => (string) ($lockedStep->capability_name ?: $lockedStep->capability_key),
                        'summary' => $message,
                        'status' => 'failed',
                        'step_id' => (string) $lockedStep->id,
                        'detail' => $this->traceForStep($lockedStep, 'failed', '')['detail'],
                        'payload' => ['failure_code' => 'worker_failed'],
                    ]);
                }
                $locked = $this->states->touchEvent($locked, [], $this->stepEvent(
                    $lockedStep,
                    $cancelled ? 'step.skipped' : 'step.failed',
                    $cancelled ? 'stopped' : 'failed',
                    $cancelled ? '运行已取消，未完成的内部步骤已停止。' : $message,
                ));
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
                $failed = $this->finalizeTerminalResponse($failed);
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
                $stoppedSteps = $lockedRun->steps()
                    ->whereIn('state', ['pending', 'failed'])
                    ->lockForUpdate()
                    ->get();
                foreach ($stoppedSteps as $stoppedStep) {
                    $stoppedStep->forceFill([
                        'state' => 'skipped',
                        'error_message' => '外部结果已确认，后续步骤因授权或能力版本变化而停止。',
                        'finished_at' => now(),
                    ])->save();
                    $updated = $this->states->touchEvent($updated, [], $this->stepEvent(
                        $stoppedStep,
                        'step.skipped',
                        'stopped',
                        '外部结果已确认，后续步骤因授权或能力版本变化而停止。',
                    ));
                }

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
                $lockedRun = $this->states->touchEvent($lockedRun, [], $this->stepEvent(
                    $lockedStep,
                    'step.skipped',
                    'stopped',
                    '运行已取消，尚未发出的外部请求已停止。',
                ));

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
            'result_schema_version' => 1,
            'first_output_at' => $lockedStep->first_output_at ?? now(),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();
        $artifact = AiWorkspaceArtifact::query()->firstOrCreate(
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

        if ($lockedStep->external_operation) {
            $operations = AiWorkspaceExternalOperation::query()
                ->where('step_id', $lockedStep->id)
                ->get(['id', 'status', 'dispatched_at', 'confirmed_at']);
            if ($operations->isEmpty()) {
                $lockedRun = $this->recordExternalEvent(
                    $lockedRun,
                    $lockedStep,
                    'external.dispatched',
                    '外部任务已提交',
                    '受控外部任务已提交，当前结果记录为能力执行回执。',
                    'completed',
                );
            } else {
                if ($operations->contains(static fn (AiWorkspaceExternalOperation $operation): bool => $operation->dispatched_at !== null)) {
                    $lockedRun = $this->recordExternalEvent(
                        $lockedRun,
                        $lockedStep,
                        'external.dispatched',
                        '外部请求已发出',
                        '外部操作账本记录了真实发出时间。',
                        'completed',
                    );
                }
                if ($operations->every(static fn (AiWorkspaceExternalOperation $operation): bool => $operation->status === 'confirmed')) {
                    $lockedRun = $this->recordExternalEvent(
                        $lockedRun,
                        $lockedStep,
                        'external.confirmed',
                        '外部结果已确认',
                        '全部外部操作结果已写入账本并完成确认。',
                        'completed',
                    );
                }
            }
        }

        $trace = $this->traceForStep($lockedStep, 'completed', '能力调用已完成，结果已写入受控产物。');
        $trace['detail']['artifact_type'] = $result->artifactType;

        $lockedRun = $this->states->touchEvent(
            $lockedRun,
            [
                'system_operations_executed' => true,
                'status_message' => '步骤已完成，正在继续执行。',
            ],
            $trace,
        );

        $lockedRun = $this->states->touchEvent($lockedRun, [], [
            'event_type' => 'artifact.created',
            'kind' => 'result',
            'title' => '结果已保存',
            'summary' => '能力结果已写入受控产物。',
            'status' => 'completed',
            'step_id' => (string) $lockedStep->id,
            'detail' => ['artifact_type' => $result->artifactType],
            'payload' => [
                'artifact_id' => (string) $artifact->id,
                'artifact_type' => $result->artifactType,
                'capability' => (string) $lockedStep->capability_key,
            ],
        ]);

        return $this->states->touchEvent($lockedRun, [], $this->stepEvent(
            $lockedStep,
            'step.completed',
            'completed',
            '步骤已完成，结果已持久化。',
        ));
    }

    private function recordExternalEvent(
        AiWorkspaceRun $run,
        AiWorkspaceStep $step,
        string $eventType,
        string $title,
        string $summary,
        string $status,
    ): AiWorkspaceRun {
        $operation = AiWorkspaceExternalOperation::query()
            ->where('step_id', $step->id)
            ->orderBy('created_at')
            ->first();

        return $this->states->touchEvent($run, [], [
            'event_type' => $eventType,
            'kind' => 'external',
            'title' => $title,
            'summary' => $summary,
            'status' => $status,
            'step_id' => (string) $step->id,
            'detail' => $this->traceForStep($step, $status, '')['detail'],
            'payload' => [
                'capability' => (string) $step->capability_key,
                'external_operation_id' => $operation?->id,
            ],
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
            ProcessAiWorkspaceRunJob::dispatch((string) $run->id)
                ->onConnection((string) config('ai-workspace.connection', config('queue.default')))
                ->onQueue($this->queueNameForRun($run));
        } catch (Throwable $exception) {
            report(new RuntimeException(AiWorkspaceErrorSanitizer::clean($exception->getMessage())));
        }
    }

    private function defer(string $runId): void
    {
        try {
            ProcessAiWorkspaceRunJob::dispatch($runId)
                ->onConnection((string) config('ai-workspace.connection', config('queue.default')))
                ->onQueue($this->queueNameForRun(AiWorkspaceRun::query()->findOrFail($runId)))
                ->delay(now()->addSeconds(5));
        } catch (Throwable $exception) {
            report(new RuntimeException(AiWorkspaceErrorSanitizer::clean($exception->getMessage())));
        }
    }

    private function assertOwner(Admin $admin, AiWorkspaceRun $run): void
    {
        if ((int) $run->admin_id !== (int) $admin->id) {
            throw (new ModelNotFoundException)->setModel(AiWorkspaceRun::class, [$run->id]);
        }
    }

    private function assertCurrentAuthority(Admin $admin, AiWorkspaceRun $run): void
    {
        $this->assertOwner($admin, $run);
        $current = Admin::query()->whereKey($admin->id)->where('status', 'active')->lockForUpdate()->first();
        if (! $current instanceof Admin
            || (int) $run->admin_auth_version <= 0
            || (int) $run->admin_auth_version !== (int) $current->auth_version
            || (int) $run->model_access_admin_id !== (int) $current->getKey()) {
            throw new RuntimeException('管理员授权已变化，当前操作已停止。');
        }
        $this->executionGuard->assertFrozenRunAdmin($run);
    }

    private function currentAdminForRun(AiWorkspaceRun $run, ?string $workerToken = null): ?Admin
    {
        try {
            if (is_string($workerToken) && $workerToken !== '') {
                $this->assertExecutionLease($run, $workerToken);
            }
            $admin = $this->executionGuard->assertFrozenRunAdmin($run);
        } catch (Throwable) {
            return null;
        }
        if ((int) $run->admin_auth_version <= 0
            || (int) $run->admin_auth_version !== (int) $admin->auth_version
            || (int) $run->admin_id !== (int) $admin->getKey()) {
            return null;
        }

        return $admin;
    }

    private function assertExecutionLease(AiWorkspaceRun $run, string $workerToken): void
    {
        if (! in_array((string) $run->state, ['running', 'cancel_requested'], true)
            || trim((string) $run->execution_lease_token) === ''
            || ! hash_equals((string) $run->execution_lease_token, $workerToken)
            || $run->execution_lease_expires_at === null
            || $run->execution_lease_expires_at->isPast()) {
            throw new RuntimeException('AI 工作台执行租约已经失效。');
        }
    }

    /** @return array{AiWorkspaceRun,AiWorkspaceStep,Admin} */
    private function lockAuthorizedClaimedStep(
        AiWorkspaceRun $run,
        AiWorkspaceStep $step,
        string $leaseOwner,
    ): array {
        $admin = Admin::query()->whereKey($run->admin_id)->where('status', 'active')->lockForUpdate()->first();
        $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
        $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
        if (! (bool) config('ai-workspace.runtime_enabled', false)
            || ! $admin instanceof Admin
            || $lockedRun->state !== 'running'
            || trim((string) $lockedRun->execution_lease_token) === ''
            || ! hash_equals((string) $lockedRun->execution_lease_token, $leaseOwner)
            || ! $lockedRun->execution_lease_expires_at?->isFuture()
            || (int) $lockedRun->admin_auth_version <= 0
            || (int) $lockedRun->admin_auth_version !== (int) $admin->auth_version
            || $lockedStep->state !== 'running'
            || ! hash_equals((string) $lockedStep->lease_owner, $leaseOwner)
            || ! $lockedStep->lease_expires_at?->isFuture()) {
            throw new RuntimeException('执行授权、运行状态或步骤租约已经变化。');
        }
        $persistedAdmin = $this->executionGuard->assertFrozenRunAdmin($lockedRun);
        if ((int) $persistedAdmin->getKey() !== (int) $admin->getKey()) {
            throw new RuntimeException('AI 工作台执行管理员身份已经变化。');
        }
        $capability = $this->registry->get((string) $lockedStep->capability_key);
        if (! $capability->allows($admin)
            || ! $capability->isExecutable()
            || ! hash_equals($capability->version, (string) $lockedStep->capability_version)
            || ! hash_equals($capability->version, (string) (((array) $lockedRun->capability_versions)[$lockedStep->capability_key] ?? ''))) {
            throw new RuntimeException('执行前权限或能力版本校验失败。');
        }

        return [$lockedRun, $lockedStep, $admin];
    }

    public function queueNameForRun(AiWorkspaceRun $run): string
    {
        $steps = $run->steps()->get();
        $interactive = $steps->isNotEmpty() && $steps->every(function (AiWorkspaceStep $step): bool {
            $capability = $this->registry->get((string) $step->capability_key);

            return $capability->maturity === 'read_ready'
                && $capability->risk === 'low'
                && $capability->executionScope === 'internal_read'
                && $capability->approvalPolicy === 'none';
        });

        return (string) config(
            $interactive ? 'ai-workspace.interactive_queue' : 'ai-workspace.queue',
            $interactive ? 'ai-workspace-interactive' : 'ai-workspace',
        );
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
            if (! $run->traceEvents()->where('event_type', 'runtime.disabled')->exists()) {
                $run = $this->states->touchEvent($run, ['status_message' => '运行时已关闭，正在安全停止工作流。'], [
                    'event_type' => 'runtime.disabled',
                    'kind' => 'guard',
                    'title' => '运行时已关闭',
                    'summary' => '运行时开关已关闭，工作流进入安全停止流程。',
                    'status' => 'attention',
                    'payload' => ['failure_code' => 'runtime_disabled'],
                ]);
            }
            $expiredApprovalCount = $run->approvals()->where('status', 'pending')->update([
                'status' => 'expired',
                'decision_reason' => 'AI 工作台运行时已关闭',
                'decided_at' => now(),
            ]);
            if ($expiredApprovalCount > 0) {
                $run = $this->states->touchEvent($run, [], [
                    'event_type' => 'approval.expired',
                    'kind' => 'approval',
                    'title' => '审批已失效',
                    'summary' => '运行时关闭后，待处理审批已统一失效。',
                    'status' => 'stopped',
                    'payload' => ['usage' => ['approvals' => $expiredApprovalCount]],
                ]);
            }
            $hasRunningStep = $run->steps()
                ->where('state', 'running')
                ->exists();
            $stepsToSkip = $run->steps()
                ->whereIn('state', $hasRunningStep ? ['pending', 'failed'] : ['pending', 'running', 'failed'])
                ->lockForUpdate()
                ->get();
            foreach ($stepsToSkip as $stepToSkip) {
                $message = $hasRunningStep
                    ? '运行时已关闭，未开始的后续步骤已停止。'
                    : '运行时已关闭，步骤已安全停止。';
                $stepToSkip->forceFill([
                    'state' => 'skipped',
                    'error_message' => $message,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                ])->save();
                $run = $this->states->touchEvent($run, [], $this->stepEvent(
                    $stepToSkip,
                    'step.skipped',
                    'stopped',
                    $message,
                ));
            }
            if ($hasRunningStep) {
                if ($run->state === 'cancel_requested') {
                    return $run;
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
            $stopped = $this->finalizeTerminalResponse($stopped);
            $this->realtime->broadcast($stopped);
        }

        return $stopped;
    }
}
