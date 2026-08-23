<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiWorkspaceChannelRevision;
use App\Models\Admin;
use App\Models\AiWorkspaceApproval;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceStep;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class AiWorkspaceDispatchGuard
{
    public function __construct(private AiCapabilityRegistry $capabilities) {}

    public function allowsDistribution(ArticleDistribution $distribution): bool
    {
        try {
            $guard = data_get($distribution->remote_meta, 'ai_workspace_guard');
            if (! is_array($guard)) {
                return true;
            }
            [$run, $step, $admin] = $this->guardActors($guard);

            return in_array((string) $run->state, ['queued', 'running', 'completed', 'partially_completed'], true)
                && $step->state === 'completed'
                && $step->capability_key === 'distribution.publish'
                && $this->distributionMatchesStep($distribution, $step)
                && $this->guardDigestsMatch($guard, $run, $step)
                && $this->capabilityAllows($run, $step, $admin)
                && $this->hasValidApproval($run, $step);
        } catch (Throwable) {
            return false;
        }
    }

    public function assertExternalStepDispatchAllowed(AiWorkspaceStep $step): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('外部派发校验必须在事务中执行。');
        }
        $run = AiWorkspaceRun::query()->whereKey($step->run_id)->lockForUpdate()->firstOrFail();
        $lockedStep = AiWorkspaceStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();
        $admin = Admin::query()->whereKey($run->admin_id)->lockForUpdate()->first();
        if (! $admin instanceof Admin
            || $run->state !== 'running'
            || $lockedStep->state !== 'running'
            || ! $lockedStep->external_operation
            || ! $lockedStep->lease_expires_at?->isFuture()
            || ! $this->capabilityAllows($run, $lockedStep, $admin)
            || ! $this->hasValidApproval($run, $lockedStep, true)) {
            throw new RuntimeException('外部请求派发前的授权或审批已经失效。');
        }
    }

    public function authorizeDistributionDispatch(ArticleDistribution $distribution): DistributionChannel
    {
        return DB::transaction(function () use ($distribution): DistributionChannel {
            $lockedDistribution = ArticleDistribution::query()->whereKey($distribution->id)->lockForUpdate()->firstOrFail();
            $guard = data_get($lockedDistribution->remote_meta, 'ai_workspace_guard');
            if (! is_array($guard) || $lockedDistribution->status !== 'sending') {
                throw new RuntimeException('AI 工作台分发记录状态无效。');
            }
            [$run, $step, $admin] = $this->guardActors($guard, true);
            if (! in_array((string) $run->state, ['queued', 'running', 'completed', 'partially_completed'], true)
                || $step->state !== 'completed'
                || $step->capability_key !== 'distribution.publish'
                || ! $this->distributionMatchesStep($lockedDistribution, $step)
                || ! $this->guardDigestsMatch($guard, $run, $step)
                || ! $this->capabilityAllows($run, $step, $admin)
                || ! $this->hasValidApproval($run, $step, true)) {
                throw new RuntimeException('AI 工作台分发授权或审批已经失效。');
            }
            $channel = DistributionChannel::query()
                ->whereKey($lockedDistribution->distribution_channel_id)
                ->lockForUpdate()
                ->firstOrFail();
            $approvedRevision = (string) ($guard['channel_revision'] ?? '');
            if ($approvedRevision === '' || ! hash_equals($approvedRevision, $this->channelRevision($channel))) {
                throw new RuntimeException('AI 工作台分发目标在审批后已变化。');
            }
            $meta = is_array($lockedDistribution->remote_meta) ? $lockedDistribution->remote_meta : [];
            $meta['ai_workspace_guard_status'] = 'dispatched';
            $meta['ai_workspace_dispatched_at'] = now()->toISOString();
            $lockedDistribution->forceFill(['remote_meta' => $meta])->save();

            return $channel;
        });
    }

    /** @return array{AiWorkspaceRun,AiWorkspaceStep,Admin} */
    private function guardActors(array $guard, bool $lock = false): array
    {
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            throw new RuntimeException('AI 工作台运行时已关闭。');
        }
        $runQuery = AiWorkspaceRun::query()->whereKey((string) ($guard['run_id'] ?? ''));
        $stepQuery = AiWorkspaceStep::query()->whereKey((string) ($guard['step_id'] ?? ''));
        if ($lock) {
            $runQuery->lockForUpdate();
            $stepQuery->lockForUpdate();
        }
        $run = $runQuery->firstOrFail();
        $step = $stepQuery->firstOrFail();
        $adminQuery = Admin::query()->whereKey((int) ($guard['admin_id'] ?? 0))->where('status', 'active');
        if ($lock) {
            $adminQuery->lockForUpdate();
        }
        $admin = $adminQuery->firstOrFail();
        if ((string) $step->run_id !== (string) $run->id
            || (int) $run->admin_id !== (int) $admin->id
            || (int) ($guard['admin_auth_version'] ?? 0) !== (int) $admin->auth_version
            || ($run->admin_auth_version !== null && (int) $run->admin_auth_version !== (int) $admin->auth_version)) {
            throw new RuntimeException('AI 工作台管理员授权已经变化。');
        }

        return [$run, $step, $admin];
    }

    private function guardDigestsMatch(array $guard, AiWorkspaceRun $run, AiWorkspaceStep $step): bool
    {
        foreach (['plan_digest', 'parameter_digest', 'target_digest'] as $digest) {
            if (! is_string($guard[$digest] ?? null)
                || ! hash_equals((string) $run->{$digest}, (string) $guard[$digest])) {
                return false;
            }
        }

        return is_string($guard['capability_version'] ?? null)
            && hash_equals((string) $step->capability_version, (string) $guard['capability_version']);
    }

    private function distributionMatchesStep(ArticleDistribution $distribution, AiWorkspaceStep $step): bool
    {
        $articleIds = array_map('intval', (array) data_get($step->target_summary, 'article_ids', []));
        $channelIds = array_map('intval', (array) data_get($step->target_summary, 'channel_ids', []));

        return in_array((int) $distribution->article_id, $articleIds, true)
            && in_array((int) $distribution->distribution_channel_id, $channelIds, true);
    }

    private function capabilityAllows(AiWorkspaceRun $run, AiWorkspaceStep $step, Admin $admin): bool
    {
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            return false;
        }
        if ($admin->status !== 'active'
            || ($run->admin_auth_version !== null && (int) $run->admin_auth_version !== (int) $admin->auth_version)) {
            return false;
        }
        $capability = $this->capabilities->get((string) $step->capability_key);

        return $capability->isExecutable()
            && $capability->allows($admin)
            && $capability->executionScope === 'external_write'
            && hash_equals($capability->version, (string) $step->capability_version)
            && hash_equals($capability->version, (string) (((array) $run->capability_versions)[$step->capability_key] ?? ''));
    }

    private function hasValidApproval(AiWorkspaceRun $run, AiWorkspaceStep $step, bool $lock = false): bool
    {
        $query = AiWorkspaceApproval::query()
            ->where('run_id', $run->id)
            ->where('admin_id', $run->admin_id)
            ->where('capability_key', $step->capability_key)
            ->where('status', 'approved')
            ->where('expires_at', '>', now());
        $step->approval_policy === 'per_step'
            ? $query->where('step_id', $step->id)
            : $query->whereNull('step_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->contains(static fn (AiWorkspaceApproval $approval): bool => $approval->isValidFor($run));
    }

    private function channelRevision(DistributionChannel $channel): string
    {
        return AiWorkspaceChannelRevision::make($channel);
    }
}
