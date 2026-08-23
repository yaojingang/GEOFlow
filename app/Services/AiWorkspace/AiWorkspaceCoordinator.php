<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityDefinition;
use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiPlanCompiler;
use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;
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
    ) {}

    public function createRun(Admin $admin, ?AiConversation $conversation, string $prompt, ?string $requestKey = null): AiWorkspaceRun
    {
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

        $created = DB::transaction(function () use ($admin, $conversation, $prompt, $requestKey): array {
            Admin::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
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
            $clarifyingRuns = AiWorkspaceRun::query()
                ->where('conversation_id', $conversation->id)
                ->where('admin_id', $admin->id)
                ->where('state', 'clarifying')
                ->latest()
                ->lockForUpdate()
                ->get();
            $parentRun = $clarifyingRuns->first();
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
                'mode' => 'workflow',
                'state' => 'received',
                'prompt' => $prompt,
                'prompt_versions' => (array) config('ai-workspace.prompt_versions', []),
                'risk_level' => 'low',
                'status_message' => 'GEOHub 正在理解请求。',
            ]);

            return ['run' => $run, 'created' => true];
        });

        /** @var AiWorkspaceRun $run */
        $run = $created['run'];
        if (! $created['created']) {
            return $run;
        }

        try {
            ResolveAiWorkspaceRunJob::dispatch((string) $run->id)
                ->onQueue((string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive'))
                ->afterCommit();
        } catch (Throwable $exception) {
            // The authoritative run is already durable. The scheduled recovery
            // command will enqueue it after the broker becomes available.
            report($exception);
        }

        return $run;
    }

    public function resolveRun(string $runId, ?string $leaseOwner = null): void
    {
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
            ])->save();

            return $locked->refresh();
        });
        if (! $run instanceof AiWorkspaceRun) {
            return;
        }
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            $this->stopResolution($runId, $leaseOwner, 'runtime_disabled', 'AI 工作台运行时已关闭。');

            return;
        }
        $admin = $this->currentAdminForRun($run);
        if (! $admin instanceof Admin) {
            $this->stopResolution($runId, $leaseOwner, 'authorization_revoked', '管理员已停用、不存在或授权版本已变化。');

            return;
        }
        $modelStatus = $this->modelReadiness->status();
        if (! $modelStatus['ready']) {
            $this->stopResolution($runId, $leaseOwner, 'model_unavailable', (string) $modelStatus['reason']);

            return;
        }

        try {
            $resolutionPrompt = $this->resolutionPrompt($run);
            $this->renewResolutionLease($runId, $leaseOwner);
            $this->assertResolutionExecutionAllowed($run, $admin);
            $resolution = $this->intentResolver->resolve($resolutionPrompt, (int) $admin->id);
            $run = $this->updateResolutionOwned($runId, $leaseOwner, [
                'mode' => $resolution->mode,
                'intent' => $resolution->intent,
                'resolution_score' => $resolution->score(),
                'candidate_capabilities' => $resolution->candidates,
                'known_parameters' => $resolution->knownParameters,
                'missing_parameters' => $resolution->missingParameters,
            ]);

            if ($resolution->mode === 'answer') {
                $this->answer($admin, $run, $leaseOwner);

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

            $planning = $this->transitionResolutionOwned($runId, $leaseOwner, 'planning', ['status_message' => '正在生成受控计划。']);
            if (! $planning instanceof AiWorkspaceRun) {
                return;
            }
            $this->realtime->broadcast($planning);
            $draftSteps = $resolution->workflowSteps;
            if ($resolution->source === 'model') {
                try {
                    $this->renewResolutionLease($runId, $leaseOwner);
                    $this->assertResolutionExecutionAllowed($planning, $admin);
                    $modelDraft = $this->runtime->draftPlan($resolutionPrompt, $resolution->toArray(), (int) $admin->id);
                    if ($modelDraft !== []) {
                        $draftSteps = $modelDraft;
                    }
                } catch (Throwable $exception) {
                    report($exception);
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
                $this->clarify($admin, $planning, $draftMissing, [], $leaseOwner);

                return;
            }
            try {
                $plan = $this->compiler->compile($admin, $resolution->intent, $draftSteps);
            } catch (ValidationException $exception) {
                $this->clarify($admin, $planning, array_keys($exception->errors()), ['部分参数格式需要重新确认。'], $leaseOwner);

                return;
            } catch (InvalidArgumentException) {
                $this->clarify($admin, $planning, [], ['目标对象不存在或已经变化，请补充有效对象。'], $leaseOwner);

                return;
            }
            $this->engine->prepare($planning, $plan, $leaseOwner);
        } catch (Throwable $exception) {
            $fresh = AiWorkspaceRun::query()->findOrFail($runId);
            if ($fresh->isTerminal()) {
                return;
            }
            $failed = $this->transitionResolutionOwned($runId, $leaseOwner, 'failed', [
                'failure_code' => 'resolution_failed',
                'failure_message' => AiWorkspaceErrorSanitizer::clean($exception->getMessage()),
                'status_message' => '请求理解或计划校验失败。',
            ]);
            if ($failed instanceof AiWorkspaceRun) {
                $this->realtime->broadcast($failed);
            }
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

    public function markJobFailure(string $runId, Throwable $exception, ?string $leaseOwner = null): void
    {
        $run = AiWorkspaceRun::query()->find($runId);
        if (! $run instanceof AiWorkspaceRun || $run->isTerminal() || ! is_string($leaseOwner) || $leaseOwner === '') {
            return;
        }
        $failed = $this->transitionResolutionOwned($runId, $leaseOwner, 'failed', [
            'failure_code' => 'resolution_worker_failed',
            'failure_message' => AiWorkspaceErrorSanitizer::clean($exception->getMessage()),
            'status_message' => '请求理解执行器异常退出。',
            'resolution_lease_owner' => null,
            'resolution_lease_expires_at' => null,
        ]);
        if ($failed instanceof AiWorkspaceRun) {
            $this->realtime->broadcast($failed);
        }
    }

    private function answer(Admin $admin, AiWorkspaceRun $run, string $leaseOwner): void
    {
        $answering = $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'answering', ['status_message' => 'GEOHub 正在回答。']);
        if (! $answering instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($answering);
        try {
            $this->renewResolutionLease((string) $run->id, $leaseOwner);
            $this->assertResolutionExecutionAllowed($answering, $admin);
            $sequence = 0;
            $answer = $this->runtime->streamAnswer(
                (string) $run->prompt,
                function (string $delta) use ($answering, &$sequence): void {
                    $this->realtime->broadcastAnswerDelta($answering, ++$sequence, $delta);
                },
                $this->conversationMessages($run),
                (int) $admin->id,
            );
        } catch (Throwable $exception) {
            report($exception);
            $message = AiWorkspaceErrorSanitizer::clean($exception->getMessage());
            $failed = $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'failed', [
                'answer' => null,
                'failure_code' => 'answer_failed',
                'failure_message' => $message !== '' ? $message : '回答生成失败。',
                'status_message' => '回答生成失败，请稍后重试。',
                'system_operations_executed' => false,
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
            ]);
            if ($failed instanceof AiWorkspaceRun) {
                $this->realtime->broadcast($failed);
            }

            return;
        }
        $this->assertResolutionExecutionAllowed($answering, $admin);
        if (! str_contains($answer, '未执行系统操作')) {
            $answer .= "\n\n本次未执行系统操作。";
        }
        $completed = $this->completeResolutionWithMessage($admin, $run, $leaseOwner, 'completed', $answer, [
            'system_operations_executed' => false,
            'status_message' => '回答已完成，本次未执行系统操作。',
        ], ['system_operations_executed' => false]);
        if (! $completed instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($completed);
    }

    private function resolutionPrompt(AiWorkspaceRun $run): string
    {
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

    /** @return list<UserMessage|AssistantMessage> */
    private function conversationMessages(AiWorkspaceRun $run): array
    {
        $records = ConversationMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('created_at')
            ->latest('id')
            ->limit(21)
            ->get()
            ->reverse()
            ->values();
        $last = $records->last();
        if ($last instanceof ConversationMessage
            && (string) $last->role === 'user'
            && hash_equals((string) $last->content, (string) $run->prompt)) {
            $records->pop();
        }

        $budget = (int) config('ai-workspace.conversation_history_char_budget', 24000);
        $messages = [];
        foreach ($records->take(-20)->reverse() as $message) {
            $remaining = min(4000, $budget);
            if ($remaining <= 0) {
                break;
            }
            $content = Str::limit((string) $message->content, $remaining, '');
            $budget -= mb_strlen($content);
            array_unshift(
                $messages,
                (string) $message->role === 'assistant'
                    ? new AssistantMessage($content)
                    : new UserMessage($content),
            );
        }

        return $messages;
    }

    /** @param list<string> $missing @param list<string> $ambiguities */
    private function clarify(Admin $admin, AiWorkspaceRun $run, array $missing, array $ambiguities, string $leaseOwner): void
    {
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
        ], ['clarification' => true]);
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
        $answer .= "\n\n本次未执行系统操作。";
        $answering = $this->transitionResolutionOwned((string) $run->id, $leaseOwner, 'answering');
        if (! $answering instanceof AiWorkspaceRun) {
            return;
        }
        $completed = $this->completeResolutionWithMessage($admin, $answering, $leaseOwner, 'completed', $answer, [
            'system_operations_executed' => false,
            'status_message' => '能力说明已完成，本次未执行系统操作。',
        ], ['system_operations_executed' => false]);
        if (! $completed instanceof AiWorkspaceRun) {
            return;
        }
        $this->realtime->broadcast($completed);
    }

    private function currentAdminForRun(AiWorkspaceRun $run): ?Admin
    {
        $admin = Admin::query()->whereKey($run->admin_id)->where('status', 'active')->first();
        if (! $admin instanceof Admin
            || $run->admin_auth_version === null
            || (int) $run->admin_auth_version !== (int) $admin->auth_version) {
            return null;
        }

        return $admin;
    }

    private function assertResolutionExecutionAllowed(AiWorkspaceRun $run, Admin $admin): void
    {
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            throw new RuntimeException('AI 工作台运行时已关闭。');
        }
        if (! $this->modelReadiness->status()['ready']) {
            throw new RuntimeException('AI 工作台模型已不可用。');
        }
        $current = $this->currentAdminForRun($run->fresh());
        if (! $current instanceof Admin || (int) $current->id !== (int) $admin->id) {
            throw new RuntimeException('管理员授权已变化，已停止请求理解。');
        }
    }

    private function stopResolution(string $runId, string $leaseOwner, string $code, string $message): void
    {
        $failed = $this->transitionResolutionOwned($runId, $leaseOwner, 'failed', [
            'failure_code' => $code,
            'failure_message' => $message,
            'status_message' => $message,
            'resolution_lease_owner' => null,
            'resolution_lease_expires_at' => null,
        ]);
        if ($failed instanceof AiWorkspaceRun) {
            $this->realtime->broadcast($failed);
        }
    }

    /** @param array<string,mixed> $attributes */
    private function updateResolutionOwned(string $runId, string $leaseOwner, array $attributes): AiWorkspaceRun
    {
        return DB::transaction(function () use ($runId, $leaseOwner, $attributes): AiWorkspaceRun {
            $run = $this->lockResolutionOwner($runId, $leaseOwner);
            $run->forceFill($attributes)->save();

            return $run->refresh();
        });
    }

    /** @param array<string,mixed> $attributes */
    private function transitionResolutionOwned(string $runId, string $leaseOwner, string $state, array $attributes = []): ?AiWorkspaceRun
    {
        try {
            return DB::transaction(function () use ($runId, $leaseOwner, $state, $attributes): AiWorkspaceRun {
                return $this->states->transition($this->lockResolutionOwner($runId, $leaseOwner), $state, $attributes);
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
    ): ?AiWorkspaceRun {
        try {
            return DB::transaction(function () use ($admin, $run, $leaseOwner, $state, $answer, $attributes, $messageMeta): AiWorkspaceRun {
                $locked = $this->lockResolutionOwner((string) $run->id, $leaseOwner);
                $completed = $this->states->transition($locked, $state, [
                    'answer' => $answer,
                    'resolution_lease_owner' => null,
                    'resolution_lease_expires_at' => null,
                ] + $attributes);
                $conversation = $this->conversations->findForAdmin($admin, (string) $locked->conversation_id, true);
                $this->conversations->append($conversation, 'assistant', $answer, ['run_id' => $locked->id] + $messageMeta);

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

    private function lockResolutionOwner(string $runId, string $leaseOwner): AiWorkspaceRun
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
