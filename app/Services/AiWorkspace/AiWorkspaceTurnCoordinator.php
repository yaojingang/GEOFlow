<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class AiWorkspaceTurnCoordinator
{
    public function __construct(
        private AiWorkspaceCoordinator $coordinator,
        private AiWorkflowEngine $engine,
        private AiConversationRepository $conversations,
        private AiWorkspaceStateMachine $states,
    ) {}

    /** @param array<string,mixed> $input */
    public function submit(Admin $admin, AiConversation $conversation, array $input): AiWorkspaceRun
    {
        $mode = (string) ($input['delivery_mode'] ?? 'new_turn');
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $runId = is_string($input['run_id'] ?? null) ? (string) $input['run_id'] : null;

        return match ($mode) {
            'cancel' => $this->cancel($admin, $conversation, $runId),
            'steer' => $this->steer($admin, $conversation, $runId, $prompt),
            'followup' => $this->coordinator->createRun(
                $admin,
                $conversation,
                $prompt,
                $this->requestKey($input),
                $this->requiredRunId($runId),
                'followup',
            ),
            default => $this->coordinator->createRun($admin, $conversation, $prompt, $this->requestKey($input)),
        };
    }

    private function cancel(Admin $admin, AiConversation $conversation, ?string $runId): AiWorkspaceRun
    {
        $run = $this->ownedRun($admin, $conversation, $runId);

        return $this->engine->cancel($admin, $run);
    }

    private function steer(Admin $admin, AiConversation $conversation, ?string $runId, string $prompt): AiWorkspaceRun
    {
        if ($prompt === '') {
            throw new RuntimeException('引导内容不能为空。');
        }

        return DB::transaction(function () use ($admin, $conversation, $runId, $prompt): AiWorkspaceRun {
            $run = AiWorkspaceRun::query()
                ->whereKey($this->requiredRunId($runId))
                ->where('admin_id', $admin->id)
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentAdmin = Admin::query()->whereKey($admin->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $currentAdmin instanceof Admin
                || (int) $run->admin_auth_version <= 0
                || (int) $run->admin_auth_version !== (int) $currentAdmin->auth_version) {
                throw new RuntimeException('管理员授权已变化，无法引导当前任务。');
            }
            if ($run->state !== 'received'
                || $run->plan !== null
                || $run->resolution_source !== null
                || $run->resolution_lease_expires_at?->isFuture()) {
                throw new RuntimeException('当前任务已进入理解或计划阶段，请使用后续消息继续。');
            }
            $this->conversations->append($conversation, 'user', $prompt, ['delivery_mode' => 'steer', 'run_id' => (string) $run->id]);

            return $this->states->touchEvent($run, [
                'prompt' => trim((string) $run->prompt."\n\n用户引导：".$prompt),
                'status_message' => '已接收引导内容，等待开始处理。',
            ], [
                'event_type' => 'run.steered',
                'kind' => 'context',
                'title' => '更新任务引导',
                'summary' => '已在计划冻结前更新任务引导。',
                'status' => 'completed',
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'payload' => ['delivery_mode' => 'steer'],
            ]);
        });
    }

    private function ownedRun(Admin $admin, AiConversation $conversation, ?string $runId): AiWorkspaceRun
    {
        return AiWorkspaceRun::query()
            ->whereKey($this->requiredRunId($runId))
            ->where('admin_id', $admin->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();
    }

    private function requiredRunId(?string $runId): string
    {
        if ($runId === null || $runId === '') {
            throw new RuntimeException('当前操作缺少运行标识。');
        }

        return $runId;
    }

    /** @param array<string,mixed> $input */
    private function requestKey(array $input): ?string
    {
        return is_string($input['request_key'] ?? null) && $input['request_key'] !== '' ? $input['request_key'] : null;
    }
}
