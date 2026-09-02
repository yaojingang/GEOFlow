<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityDefinition;
use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiIntentResolution;
use App\Ai\Workspace\AiPlanCompiler;
use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class AiWorkspaceCoordinator
{
    public function __construct(
        private AiConversationRepository $conversations,
        private AiIntentResolver $intentResolver,
        private AiCapabilityRegistry $registry,
        private AiPlanCompiler $compiler,
        private AiWorkflowEngine $engine,
        private AiWorkspaceStateMachine $states,
        private AiWorkspaceRealtimeService $realtime,
        private AiWorkspaceModelRuntime $runtime,
        private AiWorkspaceModelReadiness $modelReadiness,
        private AiWorkspaceTraceRecorder $traces,
        private AiWorkspaceQuickReply $quickReplies,
        private AiWorkspaceContextEnvelopeBuilder $contextEnvelopeBuilder,
        private AiWorkspaceReadOnlyAgentCoordinator $readOnlyAgents,
        private AiWorkspaceExecutionAccessGuard $executionGuard,
    ) {}

    public function createRun(
        Admin $admin,
        ?AiConversation $conversation,
        string $prompt,
        ?string $requestKey = null,
        ?string $parentRunId = null,
        string $deliveryMode = 'new_turn',
    ): AiWorkspaceRun {
        if ($requestKey !== null) {
            $existing = AiWorkspaceRun::query()
                ->where('admin_id', $admin->id)
                ->where('request_key', $requestKey)
                ->first();
            if ($existing instanceof AiWorkspaceRun) {
                $this->assertMatchingReplay($existing, $conversation, $prompt);

                return $existing;
            }
        }

        $created = DB::transaction(function () use ($admin, $conversation, $prompt, $requestKey, $parentRunId, $deliveryMode): array {
            $lockedAdmin = Admin::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            if ($requestKey !== null) {
                $existing = AiWorkspaceRun::query()
                    ->where('admin_id', $admin->id)
                    ->where('request_key', $requestKey)
                    ->first();
                if ($existing instanceof AiWorkspaceRun) {
                    $this->assertMatchingReplay($existing, $conversation, $prompt);

                    return ['run' => $existing, 'created' => false];
                }
            }

            $conversation ??= $this->conversations->create($admin, $prompt);
            $conversation = $this->conversations->findForAdmin($admin, (string) $conversation->id);
            $explicitParent = $parentRunId === null ? null : AiWorkspaceRun::query()
                ->whereKey($parentRunId)
                ->where('conversation_id', $conversation->id)
                ->where('admin_id', $admin->id)
                ->lockForUpdate()
                ->firstOrFail();
            $clarifyingRuns = $explicitParent instanceof AiWorkspaceRun
                ? collect()
                : AiWorkspaceRun::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('admin_id', $admin->id)
                    ->where('state', 'clarifying')
                    ->latest()
                    ->lockForUpdate()
                    ->get();
            $parentRun = $explicitParent ?? $clarifyingRuns->first();
            $activeRuns = AiWorkspaceRun::query()
                ->where('admin_id', $admin->id)
                ->whereNotIn('state', AiWorkspaceRun::TERMINAL_STATES)
                ->when($clarifyingRuns->isNotEmpty(), static fn ($query) => $query->whereNotIn('id', $clarifyingRuns->pluck('id')))
                ->count();
            if ($activeRuns >= (int) config('ai-workspace.max_active_runs_per_admin', 3)) {
                throw new RuntimeException('当前进行中的 AI 任务已达上限，请等待任务完成后再提交。');
            }

            foreach ($clarifyingRuns as $clarifyingRun) {
                $this->states->transition($clarifyingRun, 'cancelled', [
                    'status_message' => '已收到补充信息，请查看后续运行。',
                ]);
            }
            if ((string) $conversation->title === '新对话') {
                $conversation->forceFill(['title' => Str::limit(trim($prompt), 32, '')])->save();
            }
            $this->conversations->append($conversation, 'user', $prompt);

            $run = AiWorkspaceRun::query()->create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversation->id,
                'admin_id' => $admin->id,
                'admin_username_snapshot' => (string) $admin->username,
                'admin_auth_version' => (int) $admin->auth_version,
                'parent_run_id' => $parentRun?->id,
                'request_key' => $requestKey,
                'mode' => $deliveryMode === 'followup' ? 'followup' : 'workflow',
                'state' => 'received',
                'prompt' => $prompt,
                'prompt_versions' => (array) config('ai-workspace.prompt_versions', []),
                'risk_level' => 'low',
                'status_message' => 'GEOFlow 正在理解请求。',
            ]);
            $run->forceFill($this->executionGuard->snapshotForCreation($lockedAdmin))->save();
            $this->traces->recordInitial($run);

            return ['run' => $run, 'created' => true];
        });

        /** @var AiWorkspaceRun $run */
        $run = $created['run'];
        if (! $created['created']) {
            return $run;
        }

        if ($run->parent_run_id === null
            && $this->quickReplies->replyFor((string) $run->prompt) !== null) {
            $this->resolveRun((string) $run->id);

            return $run->fresh();
        }

        $parentIsActive = $run->mode === 'followup'
            && $run->parent_run_id !== null
            && AiWorkspaceRun::query()->whereKey($run->parent_run_id)->whereNotIn('state', AiWorkspaceRun::TERMINAL_STATES)->exists();
        if ($parentIsActive) {
            return $run;
        }

        try {
            $run->forceFill(['queued_at' => now()])->save();
            ResolveAiWorkspaceRunJob::dispatch((string) $run->id)
                ->onConnection((string) config('ai-workspace.interactive_connection', config('queue.default')))
                ->onQueue((string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive'))
                ->afterCommit();
        } catch (Throwable $exception) {
            // The authoritative run is already durable. The scheduled recovery
            // command will enqueue it after the broker becomes available.
            report(new RuntimeException(AiWorkspaceErrorSanitizer::clean($exception->getMessage())));
        }

        return $run;
    }

    public function resolveRun(string $runId, ?string $leaseOwner = null): void
    {
        $existing = AiWorkspaceRun::query()->findOrFail($runId);
        if ($existing->mode === 'multi_agent' && in_array((string) $existing->state, ['running', 'cancel_requested'], true)) {
            $this->readOnlyAgents->settle($existing);

            return;
        }
        $leaseOwner ??= (string) Str::uuid7();
        $run = DB::transaction(function () use ($runId, $leaseOwner): ?AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
            if ($locked->state !== 'received') {
                return null;
            }
            if ($locked->resolution_lease_expires_at?->isFuture()) {
                return null;
            }
            $locked->forceFill([
                'resolution_lease_owner' => $leaseOwner,
                'resolution_lease_expires_at' => now()->addMinutes((int) config('ai-workspace.resolution_lease_minutes', 3)),
                'resolution_attempts' => (int) $locked->resolution_attempts + 1,
                'resolution_started_at' => $locked->resolution_started_at ?? now(),
            ])->save();

            return $this->states->touchEvent($locked, [], [
                'event_type' => 'resolution.started',
                'kind' => 'analysis',
                'title' => '开始理解请求',
                'summary' => '交互执行器已领取请求理解租约。',
                'status' => 'running',
            ]);
        });
        if (! $run instanceof AiWorkspaceRun) {
            return;
        }
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            $this->stopResolution($runId, $leaseOwner, 'runtime_disabled', 'AI 工作台运行时已关闭。');

            return;
        }
        try {
            $executionContext = $this->executionGuard->contextFromResolutionRun($run, $leaseOwner);
            $admin = $this->executionGuard->assertCurrent($executionContext);
        } catch (AiWorkspaceRuntimeGuardException) {
            AiWorkspaceRun::query()
                ->whereKey($runId)
                ->where('resolution_lease_owner', $leaseOwner)
                ->update([
                    'resolution_lease_owner' => null,
                    'resolution_lease_expires_at' => null,
                ]);

            return;
        } catch (AiModelAccessException) {
            $this->stopResolution($runId, $leaseOwner, 'authorization_revoked', '管理员已停用、不存在或授权版本已变化。');

            return;
        }
        $quickReply = $run->parent_run_id === null
            ? $this->quickReplies->replyFor((string) $run->prompt)
            : null;
        if ($quickReply !== null) {
            try {
                $this->completeQuickReply($admin, $run, $leaseOwner, $quickReply);
            } catch (Throwable $exception) {
                report(new RuntimeException(AiWorkspaceErrorSanitizer::clean($exception->getMessage())));
                $this->stopResolution($runId, $leaseOwner, 'quick_reply_failed', '快速响应失败，请稍后重试。');
            } finally {
                AiWorkspaceRun::query()
                    ->whereKey($runId)
                    ->where('resolution_lease_owner', $leaseOwner)
                    ->update([
                        'resolution_lease_owner' => null,
                        'resolution_lease_expires_at' => null,
                    ]);
            }

            return;
        }
        $planUsageDelivery = null;
        try {
            $resolutionPrompt = $this->resolutionPrompt($run);
            $this->renewResolutionLease($runId, $leaseOwner);
            $this->assertResolutionExecutionAllowed($run, $admin, false);
            $modelStatus = $this->modelReadiness->status($executionContext);
            $childRulesOnly = $run->mode === 'agent_child';
            $intentReceipt = null;
            $intentUsageDelivery = null;
            if ($modelStatus['ready'] && ! $childRulesOnly) {
                $this->recordModelRequest($runId, $leaseOwner, 'intent');
                $resolution = $this->intentResolver->resolve(
                    $resolutionPrompt,
                    $executionContext,
                    function (array $telemetry, mixed $receipt = null, mixed $usageDelivery = null) use ($runId, $leaseOwner, &$intentReceipt, &$intentUsageDelivery): void {
                        $this->recordModelCompletion($runId, $leaseOwner, $telemetry);
                        if ($receipt instanceof AiWorkspaceModelExecutionReceipt) {
                            $intentReceipt = $receipt;
                        }
                        if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                            $intentUsageDelivery = $usageDelivery;
                        }
                    },
                    fn (Throwable $exception) => $this->recordModelFailure($runId, $leaseOwner, 'intent', $exception),
                );
            } else {
                $resolution = $this->intentResolver->resolveRulesOnly($resolutionPrompt);
            }
            if ($childRulesOnly && ! $this->childResolutionIsWithinDelegatedScope($run, $resolution)) {
                $this->stopResolution(
                    $runId,
                    $leaseOwner,
                    'child_scope_violation',
                    '只读子任务解析结果超出父任务授权的数据与能力范围。',
                );

                return;
            }
            if (! $modelStatus['ready'] && ! $this->canResolveWithoutModel($resolution, $admin)) {
                $this->stopResolution($runId, $leaseOwner, 'model_unavailable', (string) $modelStatus['reason']);

                return;
            }
            if ($resolution->source === 'model' && ! $intentReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
            }
            try {
                $run = $this->updateResolutionOwned($runId, $leaseOwner, [
                    'mode' => $resolution->mode,
                    'intent' => $resolution->intent,
                    'resolution_source' => $modelStatus['ready'] ? $resolution->source : 'rules',
                    'resolution_score' => $resolution->score(),
                    'candidate_capabilities' => $resolution->candidates,
                    'known_parameters' => $resolution->knownParameters,
                    'missing_parameters' => $resolution->missingParameters,
                ], modelReceipt: $intentReceipt);
                $intentUsageDelivery?->succeeded();
            } catch (AiModelAccessException $exception) {
                $intentUsageDelivery?->revoked($exception->getErrorCode());

                throw $exception;
            } catch (Throwable $exception) {
                $intentUsageDelivery?->discarded('ai_result_not_committed');

                throw $exception;
            }
            $this->realtime->broadcast($run);

            if ($resolution->mode === 'answer') {
                $this->answer($admin, $run, $leaseOwner, $executionContext);

                return;
            }
            if ($resolution->requiresClarification()) {
                $this->clarify($admin, $run, $resolution->missingParameters, $resolution->ambiguities, $leaseOwner);

                return;
            }

            $requestedSteps = collect($resolution->workflowSteps);
            if ($requestedSteps->isEmpty()) {
                $this->clarify($admin, $run, [], ['尚未识别到明确的系统操作，请确认要执行的动作。'], $leaseOwner);

                return;
            }
            $requestedCapabilities = $requestedSteps->map(
                fn (array $step): AiCapabilityDefinition => $this->registry->get((string) ($step['capability'] ?? ''))
            );
            foreach ($requestedCapabilities as $requestedCapability) {
                if (! $requestedCapability->allows($admin)) {
                    $this->rejectCapability($admin, $run, '当前管理员没有使用“'.$requestedCapability->name.'”的权限。', $leaseOwner);

                    return;
                }
                if ($requestedCapability->maturity === 'restricted') {
                    $this->rejectCapability($admin, $run, '“'.$requestedCapability->name.'”仅提供入口说明，AI 工作台不会执行该操作。', $leaseOwner);

                    return;
                }
            }
            $advisoryCapabilities = $requestedCapabilities->filter(
                static fn (AiCapabilityDefinition $requestedCapability): bool => $requestedCapability->maturity === 'advisory'
            );
            if ($advisoryCapabilities->isNotEmpty()) {
                if ($requestedCapabilities->count() === 1) {
                    $this->completeAdvisory($admin, $run, $advisoryCapabilities->first(), $leaseOwner);

                    return;
                }
                $this->clarify($admin, $run, [], ['能力说明暂时无法与执行操作合并，请确认要先完成哪一项。'], $leaseOwner);

                return;
            }

            if ($this->readOnlyAgents->supports($admin, $resolution->workflowSteps)) {
                $this->readOnlyAgents->delegate($admin, $run, $resolution->workflowSteps, $leaseOwner);

                return;
            }

            $planning = $this->transitionResolutionOwned($runId, $leaseOwner, 'planning', ['status_message' => '正在生成受控计划。']);
            if (! $planning instanceof AiWorkspaceRun) {
                return;
            }
            $this->realtime->broadcast($planning);
            $draftSteps = $resolution->workflowSteps;
            $planSourceReceipt = $intentReceipt;
            if ($resolution->source === 'model') {
                try {
                    $this->renewResolutionLease($runId, $leaseOwner);
                    $this->assertResolutionExecutionAllowed($planning, $admin);
                    $this->recordModelRequest($runId, $leaseOwner, 'plan');
                    $planReceipt = null;
                    $modelDraft = $this->runtime->draftPlan(
                        $resolutionPrompt,
                        $resolution->toArray(),
                        $executionContext,
                        function (array $telemetry, mixed $receipt = null, mixed $usageDelivery = null) use ($runId, $leaseOwner, &$planReceipt, &$planUsageDelivery): void {
                            $this->recordModelCompletion($runId, $leaseOwner, $telemetry);
                            if ($receipt instanceof AiWorkspaceModelExecutionReceipt) {
                                $planReceipt = $receipt;
                            }
                            if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                                $planUsageDelivery = $usageDelivery;
                            }
                        },
                    );
                    if ($modelDraft !== []) {
                        if (! $planReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                            throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
                        }
                        $draftSteps = $modelDraft;
                        $planSourceReceipt = $planReceipt;
                    }
                } catch (Throwable $exception) {
                    $exception instanceof AiModelAccessException
                        ? $planUsageDelivery?->revoked($exception->getErrorCode())
                        : $planUsageDelivery?->discarded('ai_result_not_committed');
                    $this->recordModelFailure($runId, $leaseOwner, 'plan', $exception);
                    if ($exception instanceof AiModelAccessException || $exception instanceof PermanentAiProviderException) {
                        throw $exception;
                    }
                    report(new RuntimeException(AiWorkspaceErrorSanitizer::clean($exception->getMessage())));
                }
            }
            if ($draftSteps === []) {
                $this->clarify($admin, $planning, [], ['计划草案为空，请确认要执行的操作。'], $leaseOwner);

                return;
            }
            foreach ($draftSteps as $draftStep) {
                $draftCapability = $this->registry->get((string) $draftStep['capability']);
                if (! $draftCapability->allows($admin) || $draftCapability->maturity === 'restricted') {
                    throw new RuntimeException('多步计划包含未授权或受限能力。');
                }
            }
            $draftMissing = collect($draftSteps)->flatMap(function (array $draftStep): array {
                $capability = $this->registry->get((string) $draftStep['capability']);
                $parameters = (array) ($draftStep['parameters'] ?? []);
                $boundFields = array_keys((array) ($draftStep['input_bindings'] ?? []));

                return collect($capability->inputSchema)
                    ->filter(static fn (array $schema, string $field): bool => (bool) ($schema['required'] ?? false)
                        && ! in_array($field, $boundFields, true)
                        && (! array_key_exists($field, $parameters) || $parameters[$field] === '' || $parameters[$field] === []))
                    ->keys()->all();
            })->unique()->values()->all();
            if ($draftMissing !== []) {
                $this->clarify($admin, $planning, $draftMissing, [], $leaseOwner, $planSourceReceipt);

                return;
            }
            try {
                $plan = $this->compiler->compile($admin, $resolution->intent, $draftSteps);
            } catch (ValidationException $exception) {
                $this->clarify(
                    $admin,
                    $planning,
                    array_keys($exception->errors()),
                    ['部分参数格式需要重新确认。'],
                    $leaseOwner,
                    $planSourceReceipt,
                );

                return;
            } catch (InvalidArgumentException) {
                $this->clarify(
                    $admin,
                    $planning,
                    [],
                    ['目标对象不存在或已经变化，请补充有效对象。'],
                    $leaseOwner,
                    $planSourceReceipt,
                );

                return;
            }
            DB::transaction(function () use ($runId, $leaseOwner, $planSourceReceipt): void {
                $locked = $this->lockResolutionOwner($runId, $leaseOwner);
                if ($planSourceReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                    $context = $this->executionGuard->contextFromResolutionRun($locked, $leaseOwner);
                    $this->executionGuard->assertReceiptCurrent($context, $planSourceReceipt);
                }
                $locked->forceFill(['resolution_finished_at' => now()])->save();
            });
            $this->engine->prepare($planning->fresh(), $plan, $leaseOwner, $planSourceReceipt);
            $planUsageDelivery?->succeeded();
        } catch (Throwable $exception) {
            $exception instanceof AiModelAccessException
                ? $planUsageDelivery?->revoked($exception->getErrorCode())
                : $planUsageDelivery?->discarded('ai_result_not_committed');
            $fresh = AiWorkspaceRun::query()->findOrFail($runId);
            if ($fresh->isTerminal()) {
                return;
            }
            $this->stopResolution(
                $runId,
                $leaseOwner,
                match (true) {
                    $exception instanceof AiModelAccessException => 'authorization_revoked',
                    $exception instanceof PermanentAiProviderException => PermanentAiProviderException::ERROR_CODE,
                    default => 'resolution_failed',
                },
                match (true) {
                    $exception instanceof AiModelAccessException => '管理员 AI 配置授权已变化，当前运行已经停止。',
                    $exception instanceof PermanentAiProviderException => PermanentAiProviderException::ERROR_CODE,
                    default => AiWorkspaceErrorSanitizer::clean($exception->getMessage()) ?: '请求理解或计划校验失败。',
                },
            );
        } finally {
            AiWorkspaceRun::query()
                ->whereKey($runId)
                ->where('resolution_lease_owner', $leaseOwner)
                ->update([
                    'resolution_lease_owner' => null,
                    'resolution_lease_expires_at' => null,
                ]);
        }
    }

    public function recoverExpiredResolutions(int $limit = 50): int
    {
        $ids = AiWorkspaceRun::query()
            ->whereIn('state', ['received', 'planning', 'answering'])
            ->where(function ($query): void {
                $query->where('state', '!=', 'received')
                    ->orWhereNotNull('queued_at')
                    ->orWhere('mode', '!=', 'followup')
                    ->orWhereNull('parent_run_id');
            })
            ->where(function ($query): void {
                $query->whereNull('resolution_lease_expires_at')
                    ->orWhere('resolution_lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');
        $recoveredCount = 0;

        foreach ($ids as $id) {
            $recovered = DB::transaction(function () use ($id): ?AiWorkspaceRun {
                $run = AiWorkspaceRun::query()->whereKey($id)->lockForUpdate()->first();
                if (! $run instanceof AiWorkspaceRun
                    || ! in_array((string) $run->state, ['received', 'planning', 'answering'], true)
                    || $run->resolution_lease_expires_at?->isFuture()) {
                    return null;
                }
                if (! $this->executionGuard->identityComplete($run)) {
                    return $this->states->transition($run, 'failed', [
                        'failure_code' => 'authorization_revoked',
                        'failure_message' => 'AI 工作台历史运行缺少完整执行身份。',
                        'status_message' => 'AI 工作台历史运行缺少完整执行身份。',
                        'retryable_failure' => false,
                    ]);
                }

                $recovered = $this->states->recoverResolution($run);
                $leaseOwner = (string) Str::uuid7();
                DB::afterCommit(static fn () => ResolveAiWorkspaceRunJob::dispatch((string) $recovered->id, $leaseOwner)
                    ->onConnection((string) config('ai-workspace.interactive_connection', config('queue.default')))
                    ->onQueue((string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive')));

                return $recovered;
            }, 3);

            if ($recovered instanceof AiWorkspaceRun) {
                $recoveredCount++;
                $this->realtime->broadcast($recovered);
            }
        }

        return $recoveredCount;
    }

    public function markJobFailure(string $runId, Throwable $exception, ?string $leaseOwner = null): void
    {
        $run = AiWorkspaceRun::query()->find($runId);
        if (! $run instanceof AiWorkspaceRun || $run->isTerminal() || ! is_string($leaseOwner) || $leaseOwner === '') {
            return;
        }
        $answer = trim((string) $run->answer);
        $admin = $run->admin;
        $authorizationValid = $this->currentAdminForRun($run) instanceof Admin;
        if (! hash_equals(trim((string) $run->resolution_lease_owner), $leaseOwner)
            || ! $run->resolution_lease_expires_at?->isFuture()) {
            return;
        }
        if (! $authorizationValid) {
            $this->stopResolution(
                $runId,
                $leaseOwner,
                'authorization_revoked',
                '管理员 AI 配置授权已变化，执行器结果已丢弃。',
            );

            return;
        }
        if ($exception instanceof PermanentAiProviderException) {
            $this->stopResolution(
                $runId,
                $leaseOwner,
                PermanentAiProviderException::ERROR_CODE,
                PermanentAiProviderException::ERROR_CODE,
            );

            return;
        }
        $failureCode = $authorizationValid ? 'resolution_worker_failed' : 'authorization_revoked';
        if ($run->state === 'answering') {
            $authorizationValid
                ? $this->recordModelFailure($runId, $leaseOwner, 'answer', $exception)
                : $this->recordModelCancellation($runId, $leaseOwner, 'authorization_revoked', '管理员授权已变化，模型输出已经停止。');
        }
        $this->recordResolutionFailure(
            $runId,
            $leaseOwner,
            $failureCode,
            $authorizationValid ? '请求理解执行器异常退出。' : '管理员账号已失效，回答生成已经停止。',
        );
        $failed = $answer === ''
            ? $this->transitionResolutionOwned($runId, $leaseOwner, 'failed', [
                'failure_code' => $failureCode,
                'failure_message' => AiWorkspaceErrorSanitizer::clean($exception->getMessage()),
                'status_message' => $authorizationValid ? '请求理解执行器异常退出。' : '管理员账号已失效，回答生成已经停止。',
                'answer_is_partial' => false,
                'resolution_finished_at' => now(),
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
            ])
            : ($admin instanceof Admin ? $this->completeResolutionWithMessage(
                $admin,
                $run,
                $leaseOwner,
                'failed',
                $answer,
                [
                    'failure_code' => $failureCode,
                    'failure_message' => AiWorkspaceErrorSanitizer::clean($exception->getMessage()),
                    'status_message' => $authorizationValid ? '回答生成中断，已保留生成内容。' : '管理员账号已失效，已保留生成内容。',
                    'answer_is_partial' => true,
                    'resolution_finished_at' => now(),
                ],
                ['system_operations_executed' => false, 'state' => 'failed', 'incomplete' => true],
            ) : $this->transitionResolutionOwned($runId, $leaseOwner, 'failed', [
                'failure_code' => 'authorization_revoked',
                'failure_message' => '管理员账号已失效，回答生成已经停止。',
                'status_message' => '管理员账号已失效，回答生成已经停止。',
                'answer_is_partial' => true,
                'resolution_finished_at' => now(),
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
            ]));
        if ($failed instanceof AiWorkspaceRun) {
            $this->realtime->broadcast($failed);
        }
    }

    private function answer(
        Admin $admin,
        AiWorkspaceRun $run,
        string $leaseOwner,
        AiWorkspaceExecutionContext $executionContext,
    ): void {
        $context = $this->contextEnvelopeBuilder->build($run->fresh());
        $this->recordContextSnapshot((string) $run->id, $leaseOwner, (string) $context['digest'], [
            'message_count' => count((array) $context['message_references']),
            'artifact_count' => count((array) $context['artifact_references']),
            'truncated' => (bool) $context['truncated'],
        ]);
        $answering = $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'answering', ['status_message' => 'GEOFlow 正在回答。'], [
            'event_type' => 'model.requested',
            'kind' => 'analysis',
            'title' => '生成回答',
            'summary' => '已提交模型请求。',
            'status' => 'running',
            'payload' => ['context_digest' => (string) $context['digest']],
        ]);
        if (! $answering instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($answering);
        try {
            $this->renewResolutionLease((string) $run->id, $leaseOwner);
            $this->assertResolutionExecutionAllowed($answering, $admin);
            $buffer = '';
            $modelTelemetry = null;
            $modelReceipt = null;
            $modelUsageDelivery = null;
            $hasPersistedChunk = (int) $answering->answer_chunk_sequence > 0;
            $lastFlushAt = microtime(true);
            $generatedAnswer = $this->runtime->streamAnswer(
                (string) $run->prompt,
                function (string $delta, AiWorkspaceModelExecutionReceipt $receipt) use ($executionContext, &$buffer, &$hasPersistedChunk, &$lastFlushAt, &$modelReceipt): void {
                    $modelReceipt = $receipt;
                    $this->assertAnswerStreamAllowed($executionContext, $receipt);
                    $buffer .= $delta;
                    if (! $hasPersistedChunk
                        || mb_strlen($buffer) >= 96
                        || (microtime(true) - $lastFlushAt) >= 0.08) {
                        $this->persistAndBroadcastAnswerChunk($executionContext, $receipt, $buffer);
                        $buffer = '';
                        $hasPersistedChunk = true;
                        $lastFlushAt = microtime(true);
                    }
                },
                $context['messages'],
                $executionContext,
                function (array $telemetry, mixed $receipt = null, mixed $usageDelivery = null) use (&$modelTelemetry, &$modelReceipt, &$modelUsageDelivery): void {
                    $modelTelemetry = $telemetry;
                    if ($receipt instanceof AiWorkspaceModelExecutionReceipt) {
                        $modelReceipt = $receipt;
                    }
                    if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                        $modelUsageDelivery = $usageDelivery;
                    }
                },
            );
            if ($buffer !== '') {
                if (! $modelReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                    throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
                }
                $this->persistAndBroadcastAnswerChunk($executionContext, $modelReceipt, $buffer);
            }
            if (! $hasPersistedChunk && trim($generatedAnswer) !== '') {
                if (! $modelReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                    throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
                }
                $this->persistAndBroadcastAnswerChunk($executionContext, $modelReceipt, $generatedAnswer);
            }
            $cancelled = $this->preserveCancelledAnswer(
                $admin,
                (string) $run->id,
                $leaseOwner,
                trim((string) AiWorkspaceRun::query()->whereKey($run->id)->value('answer')),
            );
            if ($cancelled instanceof AiWorkspaceRun) {
                $modelUsageDelivery?->discarded('ai_result_not_committed');
                $this->realtime->broadcast($cancelled);

                return;
            }
            if (is_array($modelTelemetry)) {
                $this->recordModelCompletion((string) $answering->id, $leaseOwner, $modelTelemetry);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof AiModelAccessException) {
                $modelUsageDelivery?->revoked($exception->getErrorCode());
                $this->stopResolution(
                    (string) $run->id,
                    $leaseOwner,
                    'authorization_revoked',
                    '管理员 AI 配置授权已变化，供应商结果已丢弃。',
                );

                return;
            }
            if ($exception instanceof PermanentAiProviderException) {
                $modelUsageDelivery?->discarded('ai_result_not_committed');
                $this->stopResolution(
                    (string) $run->id,
                    $leaseOwner,
                    PermanentAiProviderException::ERROR_CODE,
                    PermanentAiProviderException::ERROR_CODE,
                );

                return;
            }
            $persistedAnswer = trim((string) AiWorkspaceRun::query()->whereKey($run->id)->value('answer'));
            $cancelled = $this->preserveCancelledAnswer($admin, (string) $run->id, $leaseOwner, $persistedAnswer);
            if ($cancelled instanceof AiWorkspaceRun) {
                $modelUsageDelivery?->discarded('ai_result_not_committed');
                $this->realtime->broadcast($cancelled);

                return;
            }
            report(new RuntimeException(AiWorkspaceErrorSanitizer::clean($exception->getMessage())));
            $message = AiWorkspaceErrorSanitizer::clean($exception->getMessage());
            $failureCode = match (true) {
                str_contains($exception->getMessage(), '授权') => 'authorization_revoked',
                str_contains($exception->getMessage(), '运行时') => 'runtime_disabled',
                default => 'answer_failed',
            };
            if (in_array($failureCode, ['authorization_revoked', 'runtime_disabled'], true)) {
                $this->recordModelCancellation((string) $run->id, $leaseOwner, $failureCode, $message);
            } else {
                $this->recordModelFailure((string) $run->id, $leaseOwner, 'answer', $exception);
            }
            $this->recordResolutionFailure(
                (string) $run->id,
                $leaseOwner,
                $failureCode,
                $message !== '' ? $message : '回答生成失败。',
            );
            $attributes = [
                'answer' => $persistedAnswer !== '' ? $persistedAnswer : null,
                'failure_code' => $failureCode,
                'failure_message' => $message !== '' ? $message : '回答生成失败。',
                'status_message' => '回答生成失败，请稍后重试。',
                'system_operations_executed' => false,
                'answer_is_partial' => $persistedAnswer !== '',
                'resolution_finished_at' => now(),
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
            ];
            $failed = $persistedAnswer !== ''
                ? $this->completeResolutionWithMessage(
                    $admin,
                    $run,
                    $leaseOwner,
                    'failed',
                    $persistedAnswer,
                    $attributes,
                    ['system_operations_executed' => false, 'state' => 'failed', 'incomplete' => true],
                    $modelReceipt,
                )
                : $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'failed', $attributes);
            if ($failed instanceof AiWorkspaceRun && $failed->failure_code !== 'authorization_revoked') {
                $modelUsageDelivery?->discarded('ai_result_not_committed');
                $this->realtime->broadcast($failed);
            }

            return;
        }
        $persistedAnswer = trim((string) AiWorkspaceRun::query()->whereKey($run->id)->value('answer'));
        $cancelled = $this->preserveCancelledAnswer($admin, (string) $run->id, $leaseOwner, $persistedAnswer);
        if ($cancelled instanceof AiWorkspaceRun) {
            $modelUsageDelivery?->discarded('ai_result_not_committed');
            $this->realtime->broadcast($cancelled);

            return;
        }
        try {
            $this->assertResolutionExecutionAllowed($answering, $admin);
            $completed = $this->completeResolutionWithMessage($admin, $run, $leaseOwner, 'completed', $persistedAnswer, [
                'system_operations_executed' => false,
                'status_message' => '回答已完成。',
                'answer_is_partial' => false,
                'resolution_finished_at' => now(),
            ], ['system_operations_executed' => false], $modelReceipt);
        } catch (AiModelAccessException $exception) {
            $modelUsageDelivery?->revoked($exception->getErrorCode());
            $this->stopResolution(
                (string) $run->id,
                $leaseOwner,
                'authorization_revoked',
                '管理员 AI 配置授权已变化，供应商结果已丢弃。',
            );

            return;
        } catch (Throwable $exception) {
            $modelUsageDelivery?->discarded('ai_result_not_committed');

            throw $exception;
        }
        if (! $completed instanceof AiWorkspaceRun) {
            $modelUsageDelivery?->discarded('ai_result_not_committed');

            return;
        }
        $modelUsageDelivery?->succeeded();
        $this->realtime->broadcast($completed);
    }

    private function completeQuickReply(Admin $admin, AiWorkspaceRun $run, string $leaseOwner, string $answer): void
    {
        $completed = DB::transaction(function () use ($admin, $run, $leaseOwner, $answer): ?AiWorkspaceRun {
            $this->updateResolutionOwned((string) $run->id, $leaseOwner, [
                'mode' => 'answer',
                'intent' => 'social.greeting',
                'resolution_score' => 1,
                'candidate_capabilities' => [],
                'known_parameters' => [],
                'missing_parameters' => [],
                'resolution_source' => 'quick_reply',
                'status_message' => '已识别为简单问候，正在快速响应。',
            ]);
            $answering = $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'answering', [
                'status_message' => 'GEOFlow 正在快速响应。',
            ], [
                'event_type' => 'run.started',
                'kind' => 'analysis',
                'title' => '快速响应',
                'summary' => '已匹配本地问候响应。',
                'status' => 'running',
                'payload' => ['resolution_source' => 'quick_reply'],
            ]);
            if (! $answering instanceof AiWorkspaceRun) {
                throw new RuntimeException('快速响应租约已经失效。');
            }

            $completed = $this->completeResolutionWithMessage($admin, $answering, $leaseOwner, 'completed', $answer, [
                'system_operations_executed' => false,
                'status_message' => '已快速响应。',
                'resolution_finished_at' => now(),
            ], [
                'system_operations_executed' => false,
                'fast_path' => 'greeting',
            ]);
            if (! $completed instanceof AiWorkspaceRun) {
                throw new RuntimeException('快速响应租约已经失效。');
            }

            return $completed;
        });
        // The HTTP response contains the durable terminal snapshot. Avoid a
        // synchronous Reverb round trip on the sub-500ms greeting path.
    }

    private function preserveCancelledAnswer(Admin $admin, string $runId, string $leaseOwner, string $partialAnswer): ?AiWorkspaceRun
    {
        return DB::transaction(function () use ($admin, $runId, $leaseOwner, $partialAnswer): ?AiWorkspaceRun {
            $cancelled = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
            if ($cancelled->state !== 'cancel_requested'
                || ! hash_equals((string) $cancelled->resolution_lease_owner, $leaseOwner)) {
                return null;
            }

            $answer = trim($partialAnswer);

            $updated = $this->states->touchEvent($cancelled, [], [
                'event_type' => 'model.cancelled',
                'kind' => 'analysis',
                'title' => '模型输出已停止',
                'summary' => '已响应取消请求并停止接收后续模型输出。',
                'status' => 'stopped',
            ]);
            $updated = $this->states->transition($updated, 'cancelled', [
                'answer' => $answer !== '' ? $answer : null,
                'status_message' => $answer !== '' ? '回答已取消，已保留生成内容。' : '回答已取消。',
                'system_operations_executed' => false,
                'answer_is_partial' => $answer !== '',
                'resolution_finished_at' => now(),
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
            ]);
            if ($answer !== '') {
                $conversation = $this->conversations->findForAdmin($admin, (string) $cancelled->conversation_id, true);
                $this->conversations->saveRunResponse($conversation, $runId, $answer, [
                    'system_operations_executed' => false,
                    'state' => 'cancelled',
                    'incomplete' => true,
                ]);
            }

            return $updated;
        });
    }

    private function resolutionPrompt(AiWorkspaceRun $run): string
    {
        if ($run->mode === 'agent_child') {
            return (string) $run->prompt;
        }
        $parts = [(string) $run->prompt];
        $parentId = $run->parent_run_id;
        $visited = [];
        while ($parentId !== null && count($visited) < 5 && ! isset($visited[(string) $parentId])) {
            $visited[(string) $parentId] = true;
            $parent = AiWorkspaceRun::query()->find($parentId);
            if (! $parent instanceof AiWorkspaceRun) {
                break;
            }
            array_unshift($parts, (string) $parent->prompt);
            $parentId = $parent->parent_run_id;
        }

        return collect($parts)->filter()->values()->map(
            static fn (string $part, int $index): string => $index === 0 ? $part : '用户补充：'.$part
        )->implode("\n");
    }

    /** @param list<string> $missing @param list<string> $ambiguities */
    private function clarify(
        Admin $admin,
        AiWorkspaceRun $run,
        array $missing,
        array $ambiguities,
        string $leaseOwner,
        ?AiWorkspaceModelExecutionReceipt $modelReceipt = null,
    ): void {
        $labels = [
            'query' => '要诊断的品牌或主题', 'name' => '名称', 'title' => '文章标题',
            'content' => '正文内容', 'category_id' => '分类', 'author_id' => '作者', 'url' => 'URL',
            'article_ids' => '文章', 'channel_ids' => '目标站点', 'task_id' => '任务', 'hosted_site_id' => '托管站点',
            'job_id' => 'URL 导入任务',
        ];
        $questions = collect($missing)->map(static fn (string $field): string => $labels[$field] ?? $field)->implode('、');
        $answer = $questions !== '' ? '请补充：'.$questions.'。' : '请确认你希望执行的具体操作和目标对象。';
        if ($ambiguities !== []) {
            $answer .= "\n".implode("\n", $ambiguities);
        }
        $clarifying = $this->completeResolutionWithMessage($admin, $run, $leaseOwner, 'clarifying', $answer, [
            'status_message' => '需要补充信息后才能生成计划。',
        ], ['clarification' => true], $modelReceipt);
        if (! $clarifying instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($clarifying);
    }

    private function rejectCapability(Admin $admin, AiWorkspaceRun $run, string $answer, string $leaseOwner): void
    {
        $rejected = $this->completeResolutionWithMessage($admin, $run, $leaseOwner, 'rejected', $answer, [
            'system_operations_executed' => false,
            'status_message' => '请求已被能力与权限策略拦截。',
        ], ['rejected' => true]);
        if (! $rejected instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($rejected);
    }

    private function completeAdvisory(Admin $admin, AiWorkspaceRun $run, AiCapabilityDefinition $capability, string $leaseOwner): void
    {
        $route = collect($capability->routePatterns)->first(static fn (string $item): bool => ! str_contains($item, '*'));
        $url = $route && app('router')->has($route) ? route($route) : route('admin.ai-workspace');
        $answer = $capability->name.'：'.$capability->description."\n后台入口：".$url;
        if ($capability->key === 'system.capabilities.explain') {
            $catalog = $this->registry->visibleTo($admin)->map(static function (AiCapabilityDefinition $item): string {
                return sprintf('• %s（%s）：%s', $item->name, $item->maturity, $item->description);
            })->implode("\n");
            $answer .= "\n\n当前可用能力：\n".$catalog;
        }
        $answering = $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'answering');
        if (! $answering instanceof AiWorkspaceRun) {
            return;
        }
        $completed = $this->completeResolutionWithMessage($admin, $answering, $leaseOwner, 'completed', $answer, [
            'system_operations_executed' => false,
            'status_message' => '能力说明已完成。',
            'resolution_finished_at' => now(),
        ], ['system_operations_executed' => false]);
        if (! $completed instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($completed);
    }

    private function currentAdminForRun(AiWorkspaceRun $run): ?Admin
    {
        try {
            $admin = $this->executionGuard->assertFrozenRunAdmin($run);
        } catch (Throwable) {
            return null;
        }
        if ((int) $run->admin_id !== (int) $admin->getKey()
            || $run->admin_auth_version === null
            || (int) $run->admin_auth_version !== (int) $admin->auth_version) {
            return null;
        }

        return $admin;
    }

    private function assertResolutionExecutionAllowed(AiWorkspaceRun $run, Admin $admin, bool $requireModel = true): void
    {
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            throw new RuntimeException('AI 工作台运行时已关闭。');
        }
        $context = $this->executionGuard->contextFromResolutionRun(
            $run->fresh(),
            (string) $run->resolution_lease_owner,
        );
        $current = $this->currentAdminForRun($run->fresh());
        if (! $current instanceof Admin || (int) $current->id !== (int) $admin->id) {
            throw AiModelAccessException::configAccessRevokedForAdminId((int) $run->model_access_admin_id);
        }
        if ($requireModel && ! $this->modelReadiness->status($context)['ready']) {
            throw new RuntimeException('AI 工作台模型已不可用。');
        }
    }

    private function stopResolution(string $runId, string $leaseOwner, string $code, string $message): void
    {
        try {
            $failed = DB::transaction(function () use ($runId, $leaseOwner, $code, $message): AiWorkspaceRun {
                $run = $code === 'authorization_revoked'
                    ? $this->lockResolutionLeaseOnly($runId, $leaseOwner)
                    : $this->lockResolutionOwner($runId, $leaseOwner);
                $securityType = match ($code) {
                    'authorization_revoked' => 'authorization.revoked',
                    'runtime_disabled' => 'runtime.disabled',
                    default => null,
                };
                if ($securityType !== null) {
                    $run = $this->states->touchEvent($run, [], [
                        'event_type' => $securityType,
                        'kind' => 'guard',
                        'title' => $code === 'authorization_revoked' ? '授权已撤销' : '运行时已关闭',
                        'summary' => $message,
                        'status' => 'failed',
                        'payload' => ['failure_code' => $code],
                    ]);
                }
                $run = $this->states->touchEvent($run, [], [
                    'event_type' => 'resolution.failed',
                    'kind' => 'analysis',
                    'title' => '请求理解已停止',
                    'summary' => $message,
                    'status' => 'failed',
                    'payload' => ['failure_code' => $code],
                ]);

                return $this->states->transition($run, 'failed', [
                    'failure_code' => $code,
                    'failure_message' => $message,
                    'status_message' => $message,
                    'resolution_finished_at' => now(),
                    'resolution_lease_owner' => null,
                    'resolution_lease_expires_at' => null,
                    'answer' => $code === 'authorization_revoked' ? null : $run->answer,
                    'answer_is_partial' => $code === 'authorization_revoked' ? false : $run->answer_is_partial,
                    'retryable_failure' => ! in_array($code, [
                        'authorization_revoked',
                        PermanentAiProviderException::ERROR_CODE,
                    ], true),
                ]);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === '请求理解租约已经失效。') {
                return;
            }

            throw $exception;
        }
        if ($failed instanceof AiWorkspaceRun) {
            $this->realtime->broadcast($failed);
        }
    }

    /** @param array<string,mixed> $attributes */
    private function updateResolutionOwned(
        string $runId,
        string $leaseOwner,
        array $attributes,
        ?array $trace = null,
        ?AiWorkspaceModelExecutionReceipt $modelReceipt = null,
    ): AiWorkspaceRun {
        return DB::transaction(function () use ($runId, $leaseOwner, $attributes, $trace, $modelReceipt): AiWorkspaceRun {
            $run = $this->lockResolutionOwner($runId, $leaseOwner);
            if ($modelReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                $context = $this->executionGuard->contextFromResolutionRun($run, $leaseOwner);
                $this->executionGuard->assertReceiptCurrent($context, $modelReceipt);
            }

            return $this->states->touchEvent($run, $attributes + [
                'status_message' => '已理解请求，正在选择安全的处理路径。',
            ], $trace ?? [
                'event_type' => 'resolution.completed',
                'kind' => 'analysis',
                'title' => '理解请求',
                'summary' => '已识别目标、上下文和可用能力。',
                'status' => 'completed',
            ]);
        });
    }

    /** @param array<string,mixed> $attributes */
    private function transitionResolutionOwned(string $runId, string $leaseOwner, string $state, array $attributes = [], ?array $trace = null): ?AiWorkspaceRun
    {
        try {
            return DB::transaction(function () use ($runId, $leaseOwner, $state, $attributes, $trace): AiWorkspaceRun {
                return $this->states->transition($this->lockResolutionOwner($runId, $leaseOwner), $state, $attributes, $trace);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === '请求理解租约已经失效。') {
                return null;
            }

            throw $exception;
        }
    }

    /** @param array<string,mixed> $attributes @param array<string,mixed> $messageMeta */
    private function completeResolutionWithMessage(
        Admin $admin,
        AiWorkspaceRun $run,
        string $leaseOwner,
        string $state,
        string $answer,
        array $attributes,
        array $messageMeta,
        ?AiWorkspaceModelExecutionReceipt $modelReceipt = null,
    ): ?AiWorkspaceRun {
        try {
            return DB::transaction(function () use ($admin, $run, $leaseOwner, $state, $answer, $attributes, $messageMeta, $modelReceipt): AiWorkspaceRun {
                $locked = $this->lockResolutionOwner((string) $run->id, $leaseOwner);
                if ($modelReceipt instanceof AiWorkspaceModelExecutionReceipt) {
                    $context = $this->executionGuard->contextFromResolutionRun($locked, $leaseOwner);
                    $this->executionGuard->assertReceiptCurrent($context, $modelReceipt);
                }
                $completed = $this->states->transition($locked, $state, [
                    'answer' => $answer,
                    'answer_is_partial' => (bool) ($attributes['answer_is_partial'] ?? false),
                    'resolution_finished_at' => $attributes['resolution_finished_at'] ?? now(),
                    'resolution_lease_owner' => null,
                    'resolution_lease_expires_at' => null,
                ] + $attributes);
                $conversation = $this->conversations->findForAdmin($admin, (string) $locked->conversation_id, true);
                $this->conversations->saveRunResponse($conversation, (string) $locked->id, $answer, $messageMeta);

                return $completed;
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === '请求理解租约已经失效。') {
                return null;
            }

            throw $exception;
        }
    }

    private function renewResolutionLease(string $runId, string $leaseOwner): void
    {
        $updated = AiWorkspaceRun::query()
            ->whereKey($runId)
            ->where('resolution_lease_owner', $leaseOwner)
            ->where('resolution_lease_expires_at', '>', now())
            ->update([
                'resolution_lease_expires_at' => now()->addMinutes((int) config('ai-workspace.resolution_lease_minutes', 3)),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('请求理解租约已经失效。');
        }
    }

    private function canResolveWithoutModel(AiIntentResolution $resolution, Admin $admin): bool
    {
        if ($resolution->mode !== 'workflow' || $resolution->requiresClarification() || $resolution->workflowSteps === []) {
            return false;
        }

        return collect($resolution->workflowSteps)->every(function (array $step) use ($admin): bool {
            $capability = $this->registry->get((string) ($step['capability'] ?? ''));

            return $capability->allows($admin)
                && $capability->maturity === 'read_ready'
                && $capability->risk === 'low'
                && $capability->executionScope === 'internal_read'
                && $capability->approvalPolicy === 'none';
        });
    }

    private function childResolutionIsWithinDelegatedScope(AiWorkspaceRun $run, AiIntentResolution $resolution): bool
    {
        if ($run->mode !== 'agent_child' || $resolution->mode !== 'workflow' || $resolution->requiresClarification()) {
            return false;
        }
        $allowed = array_keys((array) $run->capability_versions);
        $requested = collect($resolution->workflowSteps)
            ->map(static fn (array $step): string => (string) ($step['capability'] ?? ''))
            ->filter()
            ->values()
            ->all();

        return count($allowed) === 1
            && count($requested) === 1
            && hash_equals((string) $allowed[0], (string) $requested[0]);
    }

    private function assertAnswerStreamAllowed(
        AiWorkspaceExecutionContext $context,
        AiWorkspaceModelExecutionReceipt $receipt,
    ): void {
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            throw new RuntimeException('AI 工作台运行时已关闭。');
        }

        $this->executionGuard->assertReceiptCurrent($context, $receipt);
    }

    private function persistAndBroadcastAnswerChunk(
        AiWorkspaceExecutionContext $context,
        AiWorkspaceModelExecutionReceipt $receipt,
        string $delta,
    ): void {
        if ($delta === '') {
            return;
        }

        $persisted = DB::transaction(function () use ($context, $receipt, $delta): AiWorkspaceRun {
            $this->executionGuard->assertReceiptCurrent($context, $receipt);
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($context->runId);

            $firstToken = $run->first_token_at === null;
            $run->forceFill([
                'answer' => (string) $run->answer.$delta,
                'first_token_at' => $run->first_token_at ?? now(),
                'answer_chunk_sequence' => (int) $run->answer_chunk_sequence + 1,
                'answer_is_partial' => true,
                'resolution_lease_expires_at' => now()->addMinutes((int) config('ai-workspace.resolution_lease_minutes', 3)),
            ])->save();

            if ($firstToken) {
                return $this->states->touchEvent($run, [], [
                    'event_type' => 'model.first_token',
                    'kind' => 'analysis',
                    'title' => '收到首个输出',
                    'summary' => '首个模型输出已持久化。',
                    'status' => 'completed',
                ]);
            }

            return $run->refresh();
        });

        $this->realtime->broadcastAnswerDelta($persisted, (int) $persisted->answer_chunk_sequence, $delta);
    }

    /** @param array<string,int|bool> $counts */
    private function recordContextSnapshot(string $runId, string $leaseOwner, string $digest, array $counts): void
    {
        DB::transaction(function () use ($runId, $leaseOwner, $digest, $counts): void {
            $run = $this->lockResolutionOwner($runId, $leaseOwner);
            $this->states->touchEvent($run, ['context_snapshot_digest' => $digest], [
                'event_type' => 'context.snapshot',
                'kind' => 'context',
                'title' => '上下文快照',
                'summary' => '已建立可追溯上下文快照。',
                'status' => 'completed',
                'visibility' => 'log_only',
                'payload' => ['context_digest' => $digest, 'usage' => $counts],
            ]);
        });
    }

    /** @param array<string,mixed> $telemetry */
    private function recordModelCompletion(string $runId, string $leaseOwner, array $telemetry): void
    {
        try {
            DB::transaction(function () use ($runId, $leaseOwner, $telemetry): void {
                $run = $this->lockResolutionOwner($runId, $leaseOwner);
                $usage = (array) $run->usage;
                foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'cache_read_tokens', 'cache_write_tokens', 'model_calls'] as $key) {
                    $usage[$key] = (int) ($usage[$key] ?? 0) + (int) data_get($telemetry, 'usage.'.$key, 0);
                }
                $this->states->touchEvent($run, [
                    'model_snapshot' => (array) ($telemetry['model_snapshot'] ?? []),
                    'usage' => $usage,
                ], [
                    'event_type' => 'model.completed',
                    'kind' => 'analysis',
                    'title' => '模型输出完成',
                    'summary' => '模型输出已完整持久化。',
                    'status' => 'completed',
                    'payload' => [
                        'model_id' => data_get($telemetry, 'model_snapshot.model_id'),
                        'provider' => data_get($telemetry, 'model_snapshot.provider'),
                        'model' => data_get($telemetry, 'model_snapshot.model'),
                        'prompt_version' => data_get($telemetry, 'model_snapshot.prompt_version'),
                        'usage' => (array) ($telemetry['usage'] ?? []),
                    ],
                ]);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== '请求理解租约已经失效。') {
                throw $exception;
            }
        }
    }

    private function recordModelRequest(string $runId, string $leaseOwner, string $stage): void
    {
        DB::transaction(function () use ($runId, $leaseOwner, $stage): void {
            $run = $this->lockResolutionOwner($runId, $leaseOwner);
            $promptVersionKey = $stage === 'plan' ? 'geohub_plan' : ($stage === 'answer' ? 'geohub' : 'intent_resolver');
            $this->states->touchEvent($run, [], [
                'event_type' => 'model.requested',
                'kind' => $stage === 'plan' ? 'plan' : 'analysis',
                'title' => $stage === 'plan' ? '生成计划草案' : '识别请求意图',
                'summary' => '已提交受控模型请求。',
                'status' => 'running',
                'payload' => ['prompt_version' => (string) config('ai-workspace.prompt_versions.'.$promptVersionKey, '')],
            ]);
        });
    }

    private function recordModelFailure(string $runId, string $leaseOwner, string $stage, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($runId, $leaseOwner, $stage, $exception): void {
                $run = $this->lockResolutionOwner($runId, $leaseOwner);
                $message = AiWorkspaceErrorSanitizer::clean($exception->getMessage());
                $failureCode = match (true) {
                    str_contains(mb_strtolower($message), '401'), str_contains(mb_strtolower($message), '403'), str_contains($message, '鉴权') => 'authentication_failed',
                    str_contains(mb_strtolower($message), 'timeout'), str_contains($message, '超时') => 'provider_timeout',
                    str_contains($message, '结构化'), str_contains($message, '字段') => 'structured_output_invalid',
                    default => 'provider_unavailable',
                };
                $this->states->touchEvent($run, [], [
                    'event_type' => 'model.failed',
                    'kind' => $stage === 'plan' ? 'plan' : 'analysis',
                    'title' => $stage === 'plan' ? '计划模型调用失败' : '模型调用失败',
                    'summary' => $message !== '' ? $message : '模型调用失败。',
                    'status' => 'failed',
                    'payload' => ['failure_code' => $failureCode],
                ]);
            });
        } catch (RuntimeException $leaseException) {
            if ($leaseException->getMessage() !== '请求理解租约已经失效。') {
                throw $leaseException;
            }
        }
    }

    private function recordModelCancellation(string $runId, string $leaseOwner, string $code, string $message): void
    {
        try {
            DB::transaction(function () use ($runId, $leaseOwner, $code, $message): void {
                $run = $this->lockResolutionOwner($runId, $leaseOwner);
                $this->states->touchEvent($run, [], [
                    'event_type' => 'model.cancelled',
                    'kind' => 'analysis',
                    'title' => '模型输出已停止',
                    'summary' => $message !== '' ? $message : '模型输出已经停止。',
                    'status' => 'stopped',
                    'payload' => ['failure_code' => $code],
                ]);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== '请求理解租约已经失效。') {
                throw $exception;
            }
        }
    }

    private function recordResolutionFailure(string $runId, string $leaseOwner, string $code, string $message): void
    {
        try {
            DB::transaction(function () use ($runId, $leaseOwner, $code, $message): void {
                $run = $this->lockResolutionOwner($runId, $leaseOwner);
                $securityType = match ($code) {
                    'authorization_revoked' => 'authorization.revoked',
                    'runtime_disabled' => 'runtime.disabled',
                    default => null,
                };
                if ($securityType !== null) {
                    $run = $this->states->touchEvent($run, [], [
                        'event_type' => $securityType,
                        'kind' => 'guard',
                        'title' => $code === 'authorization_revoked' ? '授权已撤销' : '运行时已关闭',
                        'summary' => $message,
                        'status' => 'failed',
                        'payload' => ['failure_code' => $code],
                    ]);
                }
                $this->states->touchEvent($run, [], [
                    'event_type' => 'resolution.failed',
                    'kind' => 'analysis',
                    'title' => '本轮处理已停止',
                    'summary' => $message,
                    'status' => 'failed',
                    'payload' => ['failure_code' => $code],
                ]);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== '请求理解租约已经失效。') {
                throw $exception;
            }
        }
    }

    private function lockResolutionOwner(string $runId, string $leaseOwner): AiWorkspaceRun
    {
        $run = $this->lockResolutionLeaseOnly($runId, $leaseOwner);
        $this->executionGuard->assertCurrent(
            $this->executionGuard->contextFromResolutionRun($run, $leaseOwner),
        );

        return $run;
    }

    private function lockResolutionLeaseOnly(string $runId, string $leaseOwner): AiWorkspaceRun
    {
        $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);
        if (! hash_equals((string) $run->resolution_lease_owner, $leaseOwner)
            || ! $run->resolution_lease_expires_at?->isFuture()) {
            throw new RuntimeException('请求理解租约已经失效。');
        }

        return $run;
    }

    private function assertMatchingReplay(AiWorkspaceRun $run, ?AiConversation $conversation, string $prompt): void
    {
        $sameConversation = ! $conversation instanceof AiConversation
            || hash_equals((string) $run->conversation_id, (string) $conversation->id);
        if (! $sameConversation || ! hash_equals((string) $run->prompt, $prompt)) {
            throw new RuntimeException('请求标识已经绑定到其他消息，请使用新的请求标识。');
        }
    }
}
