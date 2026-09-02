<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiPayloadDigest;
use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\Admin;
use App\Models\AiWorkspaceArtifact;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class AiWorkspaceReadOnlyAgentCoordinator
{
    public function __construct(
        private AiCapabilityRegistry $registry,
        private AiWorkspaceStateMachine $states,
        private AiWorkspaceTraceRecorder $traces,
        private AiConversationRepository $conversations,
        private AiWorkspaceRealtimeService $realtime,
        private AiWorkspaceExecutionAccessGuard $executionGuard,
    ) {}

    /** @param list<array<string,mixed>> $steps */
    public function supports(Admin $admin, array $steps): bool
    {
        $maximum = (int) config('ai-workspace.read_only_agents.max_children', 3);
        if (! (bool) config('ai-workspace.read_only_agents.enabled', true)
            || count($steps) < 2
            || count($steps) > $maximum) {
            return false;
        }
        $allowed = (array) config('ai-workspace.read_only_agents.capabilities', []);

        return collect($steps)->every(function (array $step) use ($admin, $allowed): bool {
            $key = (string) ($step['capability'] ?? '');
            if (! in_array($key, $allowed, true)) {
                return false;
            }
            $capability = $this->registry->get($key);

            return $capability->allows($admin)
                && $capability->maturity === 'read_ready'
                && $capability->risk === 'low'
                && $capability->executionScope === 'internal_read'
                && $capability->approvalPolicy === 'none';
        });
    }

    /** @param list<array<string,mixed>> $steps */
    public function delegate(Admin $admin, AiWorkspaceRun $parent, array $steps, string $leaseOwner): AiWorkspaceRun
    {
        if (! $this->supports($admin, $steps)) {
            throw new RuntimeException('当前任务不符合只读多 Agent 的治理边界。');
        }

        $delegated = DB::transaction(function () use ($admin, $parent, $steps, $leaseOwner): array {
            $lockedAdmin = Admin::query()->whereKey($admin->id)->where('status', 'active')->lockForUpdate()->first();
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($parent->id);
            if (! $lockedAdmin instanceof Admin
                || (int) $locked->admin_auth_version <= 0
                || (int) $locked->admin_auth_version !== (int) $lockedAdmin->auth_version
                || $locked->state !== 'received'
                || ! hash_equals((string) $locked->resolution_lease_owner, $leaseOwner)
                || ! $locked->resolution_lease_expires_at?->isFuture()) {
                throw new RuntimeException('只读子任务拆分时授权、状态或租约已经变化。');
            }
            $persistedAdmin = $this->executionGuard->assertFrozenRunAdmin($locked);
            if ((int) $persistedAdmin->getKey() !== (int) $lockedAdmin->getKey()) {
                throw new RuntimeException('只读子任务拆分时 AI 执行身份已经变化。');
            }
            if ($locked->childRuns()->exists()) {
                throw new RuntimeException('当前父任务已经建立子任务。');
            }

            $children = collect($steps)->values()->map(function (array $step, int $index) use ($locked): AiWorkspaceRun {
                $capability = $this->registry->get((string) $step['capability']);
                $prompt = $this->childPrompt($capability->key, (array) ($step['parameters'] ?? []));
                $child = AiWorkspaceRun::query()->create([
                    'id' => (string) Str::uuid7(),
                    'conversation_id' => $locked->conversation_id,
                    'admin_id' => $locked->admin_id,
                    'admin_username_snapshot' => $locked->admin_username_snapshot,
                    'admin_auth_version' => $locked->admin_auth_version,
                    'parent_run_id' => $locked->id,
                    'request_key' => 'aiw-child:'.hash('sha256', $locked->id.'|'.$index.'|'.$capability->key),
                    'mode' => 'agent_child',
                    'state' => 'received',
                    'prompt' => $prompt,
                    'prompt_versions' => (array) config('ai-workspace.prompt_versions', []),
                    'capability_versions' => [$capability->key => $capability->version],
                    'risk_level' => 'low',
                    'queued_at' => now(),
                    'status_message' => '只读子任务已建立，等待执行。',
                ]);
                $child->forceFill([
                    'model_access_admin_id' => $locked->model_access_admin_id,
                    'model_access_admin_role' => $locked->model_access_admin_role,
                    'ai_config_access_version' => $locked->ai_config_access_version,
                    'requested_ai_model_id' => $locked->requested_ai_model_id,
                    'resolver_policy_version' => $locked->resolver_policy_version,
                ])->save();
                $this->traces->recordInitial($child);
                $this->states->touchEvent($child, [], [
                    'event_type' => 'run.child_created',
                    'kind' => 'queue',
                    'title' => $capability->name,
                    'summary' => '只读子任务已进入交互队列。',
                    'status' => 'running',
                    'payload' => [
                        'parent_run_id' => (string) $locked->id,
                        'capability' => $capability->key,
                        'budget' => [
                            'model_calls' => (int) config('ai-workspace.read_only_agents.max_model_calls', 0),
                            'total_tokens' => (int) config('ai-workspace.read_only_agents.max_total_tokens', 0),
                            'data_rows' => (int) config('ai-workspace.read_only_agents.max_data_rows', 1500),
                        ],
                    ],
                ]);

                return $child->fresh();
            })->all();

            $locked = $this->states->transition($locked, 'planning', [
                'mode' => 'multi_agent',
                'resolution_finished_at' => now(),
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
                'status_message' => '正在拆分只读分析任务。',
            ], [
                'event_type' => 'plan.drafted',
                'kind' => 'plan',
                'title' => '拆分只读任务',
                'summary' => sprintf('已建立 %d 个权限收窄的只读子任务。', count($children)),
                'status' => 'completed',
                'payload' => ['budget' => ['children' => count($children)]],
            ]);
            $locked = $this->states->transition($locked, 'validating_plan', ['status_message' => '只读子任务边界已校验。']);
            $locked = $this->states->transition($locked, 'queued', ['status_message' => '只读子任务已进入交互队列。']);
            $locked = $this->states->transition($locked, 'running', [
                'started_at' => $locked->started_at ?? now(),
                'status_message' => '正在并行处理只读子任务。',
            ]);
            foreach ($children as $child) {
                DB::afterCommit(static fn () => ResolveAiWorkspaceRunJob::dispatch((string) $child->id)
                    ->onConnection((string) config('ai-workspace.interactive_connection', config('queue.default')))
                    ->onQueue((string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive')));
            }

            return ['parent' => $locked, 'children' => $children];
        });
        $this->realtime->broadcast($delegated['parent']);

        return $delegated['parent'];
    }

    public function settle(AiWorkspaceRun $parent): ?AiWorkspaceRun
    {
        $settled = DB::transaction(function () use ($parent): ?AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($parent->id);
            if ($locked->mode !== 'multi_agent' || ! in_array((string) $locked->state, ['running', 'cancel_requested'], true)) {
                return null;
            }
            $admin = Admin::query()->whereKey($locked->admin_id)->where('status', 'active')->lockForUpdate()->first();
            try {
                $persistedAdmin = $this->executionGuard->assertFrozenRunAdmin($locked);
            } catch (Throwable) {
                $persistedAdmin = null;
            }
            if (! $admin instanceof Admin
                || ! $persistedAdmin instanceof Admin
                || (int) $persistedAdmin->getKey() !== (int) $admin->getKey()
                || (int) $locked->admin_auth_version <= 0
                || (int) $locked->admin_auth_version !== (int) $admin->auth_version) {
                $this->states->touchEvent($locked, [], [
                    'event_type' => 'authorization.revoked',
                    'kind' => 'guard',
                    'title' => '授权已撤销',
                    'summary' => '父任务汇总前的持续授权校验未通过。',
                    'status' => 'failed',
                ]);

                return $this->states->transition($locked, 'failed', [
                    'failure_code' => 'authorization_revoked',
                    'failure_message' => '管理员授权已变化。',
                    'status_message' => '父任务已停止汇总。',
                ]);
            }
            $durationLimit = max(1, (int) config('ai-workspace.read_only_agents.max_duration_seconds', 120));
            if ($locked->state === 'running'
                && $locked->started_at !== null
                && $locked->started_at->lte(now()->subSeconds($durationLimit))) {
                $locked = $this->states->transition($locked, 'cancel_requested', [
                    'cancel_requested_at' => now(),
                    'failure_code' => 'multi_agent_time_budget_exceeded',
                    'failure_message' => '只读子任务已达到总耗时上限。',
                    'status_message' => '只读子任务达到总耗时上限，正在收口。',
                ]);
                foreach ($locked->childRuns()->whereNotIn('state', AiWorkspaceRun::TERMINAL_STATES)->lockForUpdate()->get() as $child) {
                    $this->states->transition($child, $child->state === 'running' ? 'cancel_requested' : 'cancelled', [
                        'cancel_requested_at' => now(),
                        'status_message' => '父任务达到总耗时上限，子任务正在停止。',
                    ]);
                }
            }
            $children = $locked->childRuns()->lockForUpdate()->get();
            if ($children->isEmpty() || $children->contains(static fn (AiWorkspaceRun $child): bool => ! $child->isTerminal())) {
                return null;
            }
            $artifacts = AiWorkspaceArtifact::query()
                ->whereIn('run_id', $children->pluck('id'))
                ->where('type', '!=', 'conversation_summary')
                ->orderBy('created_at')
                ->get();
            $references = $artifacts->map(static fn (AiWorkspaceArtifact $artifact): array => [
                'id' => (string) $artifact->id,
                'run_id' => (string) $artifact->run_id,
                'type' => (string) $artifact->type,
                'digest' => AiPayloadDigest::make(['content' => $artifact->content, 'payload' => $artifact->payload]),
            ])->values()->all();
            $summary = $artifacts->pluck('content')->filter()->implode("\n");
            if ($summary === '') {
                $summary = '只读子任务已结束，当前没有可汇总的持久化结果。';
            }
            $locked->artifacts()->firstOrCreate(
                ['type' => 'multi_agent_summary'],
                [
                    'id' => (string) Str::uuid7(),
                    'step_id' => null,
                    'created_by_admin_id' => $admin->id,
                    'created_by_username_snapshot' => $admin->username,
                    'name' => '只读多 Agent 汇总',
                    'data_classification' => 'internal',
                    'content' => $summary,
                    'payload' => ['child_artifacts' => $references],
                    'expires_at' => now()->addDays((int) config('ai-workspace.retention_days', 90)),
                ],
            );
            $states = $children->pluck('state');
            $terminal = $states->contains('outcome_unknown')
                ? 'outcome_unknown'
                : ($locked->state === 'cancel_requested'
                    ? ($artifacts->isNotEmpty() ? 'partially_completed' : 'cancelled')
                    : ($states->contains(fn (string $state): bool => in_array($state, ['failed', 'cancelled', 'rejected'], true))
                    ? ($artifacts->isNotEmpty() ? 'partially_completed' : 'failed')
                    : 'completed'));
            $locked = $this->states->touchEvent($locked, [], [
                'event_type' => 'run.children_joined',
                'kind' => 'result',
                'title' => '子任务已汇总',
                'summary' => sprintf('已汇总 %d 个只读子任务的持久化结果。', $children->count()),
                'status' => $terminal === 'completed' ? 'completed' : 'attention',
                'payload' => ['budget' => ['children' => $children->count()], 'usage' => ['artifacts' => $artifacts->count()]],
            ]);
            $locked = $this->states->transition($locked, $terminal, [
                'answer' => $summary,
                'system_operations_executed' => $artifacts->isNotEmpty(),
                'status_message' => $terminal === 'completed' ? '只读分析已完成。' : '只读分析已部分完成。',
            ]);
            $conversation = $this->conversations->findForAdmin($admin, (string) $locked->conversation_id, true);
            $this->conversations->saveRunResponse($conversation, (string) $locked->id, $summary, [
                'system_operations_executed' => $artifacts->isNotEmpty(),
                'multi_agent' => true,
                'child_count' => $children->count(),
            ]);

            return $locked;
        });
        if ($settled instanceof AiWorkspaceRun) {
            $this->realtime->broadcast($settled);
        }

        return $settled;
    }

    /** @param array<string,mixed> $parameters */
    private function childPrompt(string $capability, array $parameters): string
    {
        $safe = collect($parameters)->only(['date', 'end_date', 'query', 'days', 'theme'])->map(
            static fn (mixed $value): string|int => is_int($value) ? $value : Str::limit(strip_tags((string) $value), 200, ''),
        )->all();
        $suffix = $safe === [] ? '' : ' 参数：'.(json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return match ($capability) {
            'analytics.daily_report' => '生成运营日报。'.$suffix,
            'analytics.weekly_report' => '生成运营周报。'.$suffix,
            'visibility.diagnose' => '执行 AI 可见性诊断。'.$suffix,
            'content.opportunities' => '分析内容机会。'.$suffix,
            default => throw new RuntimeException('不支持的只读子任务能力。'),
        };
    }
}
