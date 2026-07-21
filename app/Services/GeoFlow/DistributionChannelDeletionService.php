<?php

namespace App\Services\GeoFlow;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Models\Admin;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelOperation;
use App\Models\DistributionLog;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistributionChannelDeletionService
{
    public function isSchemaReady(): bool
    {
        return Schema::hasTable((new DistributionChannelOperation)->getTable());
    }

    /**
     * @return array{
     *     linked_task_count:int,
     *     tasks_detach_only:int,
     *     tasks_switch_to_local_only:int,
     *     tasks_pause_distribution_only:int,
     *     remote_content_count:int,
     *     secret_count:int,
     *     log_count:int,
     *     queued_count:int,
     *     sending_count:int,
     *     fresh_sending_count:int,
     *     stale_sending_count:int,
     *     active_operation_count:int,
     *     fresh_operation_count:int,
     *     stale_operation_count:int,
     *     stale_after_seconds:int,
     *     can_delete:bool,
     *     requires_force:bool,
     *     impact_fingerprint:string,
     *     remote_cleanup_manifest:list<array<string,mixed>>
     * }
     */
    public function inspect(DistributionChannel $channel): array
    {
        $channelId = (int) $channel->id;
        $tasks = Task::query()
            ->whereHas('distributionChannels', fn ($query) => $query->whereKey($channelId))
            ->withCount([
                'distributionChannels as other_distribution_channels_count' => fn ($query) => $query->whereKeyNot($channelId),
            ])
            ->orderBy('tasks.id')
            ->get(['tasks.id', 'tasks.publish_scope']);

        $staleAfterSeconds = $this->staleAfterSeconds();
        $staleCutoff = Carbon::now()->subSeconds($staleAfterSeconds);
        $distributions = ArticleDistribution::query()
            ->with('article:id,title,slug')
            ->where('distribution_channel_id', $channelId)
            ->orderBy('id')
            ->get();
        $sending = $distributions->where('status', 'sending');
        $freshSending = $sending->filter(function (ArticleDistribution $distribution) use ($staleCutoff): bool {
            $referenceAt = $distribution->last_attempt_at ?? $distribution->updated_at;

            return $referenceAt !== null && $referenceAt->gt($staleCutoff);
        });
        $staleSending = $sending->reject(function (ArticleDistribution $distribution) use ($staleCutoff): bool {
            $referenceAt = $distribution->last_attempt_at ?? $distribution->updated_at;

            return $referenceAt !== null && $referenceAt->gt($staleCutoff);
        });
        $operations = DistributionChannelOperation::query()
            ->where('distribution_channel_id', $channelId)
            ->orderBy('id')
            ->get();
        $freshOperations = $operations->filter(
            static fn (DistributionChannelOperation $operation): bool => $operation->expires_at !== null && $operation->expires_at->isFuture()
        );
        $staleOperations = $operations->reject(
            static fn (DistributionChannelOperation $operation): bool => $operation->expires_at !== null && $operation->expires_at->isFuture()
        );
        $secretIds = $channel->secrets()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $remoteCleanupManifest = $distributions
            ->filter(fn (ArticleDistribution $distribution): bool => $this->hasRemoteContent($distribution))
            ->map(fn (ArticleDistribution $distribution): array => [
                'article_distribution_id' => (int) $distribution->id,
                'article_id' => (int) $distribution->article_id,
                'article_title' => (string) ($distribution->article?->title ?? ''),
                'article_slug' => (string) ($distribution->article?->slug ?? ''),
                'action' => (string) $distribution->action,
                'status' => (string) $distribution->status,
                'remote_id' => $distribution->remote_id === null ? null : (string) $distribution->remote_id,
                'remote_url' => $this->auditRemoteUrl($distribution->remote_url),
            ])
            ->values()
            ->all();
        $fingerprintPayload = [
            'channel' => [$channelId, (string) $channel->status],
            'tasks' => $tasks->map(static fn (Task $task): array => [
                (int) $task->id,
                (string) $task->publish_scope,
                (int) $task->other_distribution_channels_count,
            ])->values()->all(),
            'distributions' => $distributions->map(static fn (ArticleDistribution $distribution): array => [
                (int) $distribution->id,
                (string) $distribution->action,
                (string) $distribution->status,
                $distribution->remote_id,
                $distribution->remote_url,
                $distribution->last_attempt_at?->toISOString(),
                $distribution->updated_at?->toISOString(),
            ])->values()->all(),
            'secrets' => $secretIds,
            'operations' => $operations->map(static fn (DistributionChannelOperation $operation): array => [
                (int) $operation->id,
                (string) $operation->token,
                (string) $operation->operation,
                $operation->expires_at?->toISOString(),
            ])->values()->all(),
        ];

        return [
            'linked_task_count' => $tasks->count(),
            'tasks_detach_only' => $tasks->filter(fn (Task $task): bool => (int) $task->other_distribution_channels_count > 0)->count(),
            'tasks_switch_to_local_only' => $tasks->filter(fn (Task $task): bool => (int) $task->other_distribution_channels_count === 0 && (string) $task->publish_scope === 'local_and_distribution')->count(),
            'tasks_pause_distribution_only' => $tasks->filter(fn (Task $task): bool => (int) $task->other_distribution_channels_count === 0 && (string) $task->publish_scope === 'distribution_only')->count(),
            'remote_content_count' => count($remoteCleanupManifest),
            'secret_count' => count($secretIds),
            'log_count' => $channel->logs()->count(),
            'queued_count' => $distributions->where('status', 'queued')->count(),
            'sending_count' => $sending->count(),
            'fresh_sending_count' => $freshSending->count(),
            'stale_sending_count' => $staleSending->count(),
            'active_operation_count' => $operations->count(),
            'fresh_operation_count' => $freshOperations->count(),
            'stale_operation_count' => $staleOperations->count(),
            'stale_after_seconds' => $staleAfterSeconds,
            'can_delete' => (string) $channel->status === DistributionChannel::STATUS_DELETING
                && $sending->isEmpty()
                && $operations->isEmpty(),
            'requires_force' => $staleSending->isNotEmpty() || $staleOperations->isNotEmpty(),
            'impact_fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
            'remote_cleanup_manifest' => $remoteCleanupManifest,
        ];
    }

    public function staleAfterSeconds(): int
    {
        $connection = (string) config('queue.default', 'database');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 360);

        return max(150, $retryAfter + 30);
    }

    public function prepare(DistributionChannel $channel): DistributionChannel
    {
        return DB::transaction(function () use ($channel): DistributionChannel {
            $lockedChannel = DistributionChannel::query()
                ->whereKey((int) $channel->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedChannel->status !== DistributionChannel::STATUS_DELETING) {
                $lockedChannel->forceFill([
                    'status' => DistributionChannel::STATUS_DELETING,
                    'last_error_message' => null,
                ])->save();
            }

            ArticleDistribution::query()
                ->where('distribution_channel_id', (int) $lockedChannel->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => __('admin.distribution.delete.queued_cancelled_error'),
                    'updated_at' => now(),
                ]);

            return $lockedChannel->fresh();
        });
    }

    public function cancel(DistributionChannel $channel): DistributionChannel
    {
        return DB::transaction(function () use ($channel): DistributionChannel {
            $lockedChannel = DistributionChannel::query()
                ->whereKey((int) $channel->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedChannel->status === DistributionChannel::STATUS_DELETING) {
                $lockedChannel->forceFill([
                    'status' => DistributionChannel::STATUS_PAUSED,
                    'last_error_message' => null,
                ])->save();
            }

            return $lockedChannel->fresh();
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function delete(
        DistributionChannel $channel,
        Admin $admin,
        DistributionChannelDeletionConfirmation $confirmation,
    ): array {
        if (! $admin->isSuperAdmin()) {
            throw new DistributionChannelDeletionBlocked('super_admin_required');
        }

        return DB::transaction(function () use ($channel, $admin, $confirmation): array {
            $lockedChannel = DistributionChannel::query()
                ->whereKey((int) $channel->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $lockedChannel->status !== DistributionChannel::STATUS_DELETING) {
                throw new DistributionChannelDeletionBlocked('prepare_required');
            }

            $channelId = (int) $lockedChannel->id;
            $tasks = Task::query()
                ->whereHas('distributionChannels', fn ($query) => $query->whereKey($channelId))
                ->orderBy('tasks.id')
                ->lockForUpdate()
                ->get();

            $impact = $this->inspect($lockedChannel);
            if (! hash_equals((string) $impact['impact_fingerprint'], $confirmation->impactFingerprint)) {
                throw new DistributionChannelDeletionBlocked('impact_changed');
            }
            $this->assertImpactAcknowledged($impact, $confirmation);
            if ($impact['fresh_sending_count'] > 0) {
                throw new DistributionChannelDeletionBlocked('sending_in_progress');
            }
            if ($impact['stale_sending_count'] > 0 && ! $confirmation->forceStaleSending) {
                throw new DistributionChannelDeletionBlocked('stale_sending_requires_force');
            }
            if ($impact['fresh_operation_count'] > 0) {
                throw new DistributionChannelDeletionBlocked('operation_in_progress');
            }
            if ($impact['stale_operation_count'] > 0 && ! $confirmation->forceStaleOperations) {
                throw new DistributionChannelDeletionBlocked('stale_operation_requires_force');
            }

            $taskIds = $tasks->pluck('id')->map(static fn ($id): int => (int) $id);
            $otherChannelCounts = DB::table('task_distribution_channels')
                ->whereIn('task_id', $taskIds->all())
                ->where('distribution_channel_id', '!=', $channelId)
                ->selectRaw('task_id, COUNT(*) as aggregate_count')
                ->groupBy('task_id')
                ->pluck('aggregate_count', 'task_id');
            $tasksWithoutOtherChannels = $tasks->filter(
                static fn (Task $task): bool => (int) ($otherChannelCounts[(int) $task->id] ?? 0) === 0
            );
            $switchToLocalIds = $tasksWithoutOtherChannels
                ->where('publish_scope', 'local_and_distribution')
                ->pluck('id')
                ->all();
            $pauseIds = $tasksWithoutOtherChannels
                ->where('publish_scope', 'distribution_only')
                ->pluck('id')
                ->all();

            if ($taskIds->isNotEmpty()) {
                Task::query()->whereIn('id', $taskIds->all())->update([
                    'distribution_cursor' => 0,
                    'updated_at' => now(),
                ]);
            }
            if ($switchToLocalIds !== []) {
                Task::query()->whereIn('id', $switchToLocalIds)->update([
                    'publish_scope' => 'local_only',
                    'updated_at' => now(),
                ]);
            }
            if ($pauseIds !== []) {
                Task::query()->whereIn('id', $pauseIds)->update([
                    'status' => 'paused',
                    'last_error_at' => now(),
                    'updated_at' => now(),
                    'last_error_message' => __('admin.distribution.delete.task_paused_error', [
                        'channel' => (string) $lockedChannel->name,
                    ]),
                ]);
            }

            $distributionIds = ArticleDistribution::query()
                ->where('distribution_channel_id', $channelId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            DB::table('task_distribution_channels')
                ->where('distribution_channel_id', $channelId)
                ->delete();
            if ($distributionIds !== []) {
                DistributionLog::query()
                    ->whereIn('article_distribution_id', $distributionIds)
                    ->update(['article_distribution_id' => null]);
            }
            ArticleDistribution::query()
                ->where('distribution_channel_id', $channelId)
                ->delete();
            $lockedChannel->secrets()->delete();
            $lockedChannel->operations()->delete();

            $auditImpact = $impact;
            unset($auditImpact['impact_fingerprint'], $auditImpact['remote_cleanup_manifest']);

            $context = [
                'event' => 'channel.deleted',
                'channel' => [
                    'id' => $channelId,
                    'name' => (string) $lockedChannel->name,
                    'domain' => (string) $lockedChannel->domain,
                    'channel_type' => (string) $lockedChannel->channel_type,
                    'status' => (string) $lockedChannel->status,
                ],
                'deleted_by' => [
                    'admin_id' => (int) $admin->id,
                    'username' => (string) $admin->username,
                ],
                'impact' => $auditImpact,
                'remote_cleanup_manifest' => $impact['remote_cleanup_manifest'],
                'force_stale_sending' => $confirmation->forceStaleSending,
                'force_stale_operations' => $confirmation->forceStaleOperations,
            ];
            DistributionLog::query()->create([
                'distribution_channel_id' => $channelId,
                'level' => 'warning',
                'event' => 'channel.deleted',
                'message' => __('admin.distribution.delete.audit_message', ['channel' => (string) $lockedChannel->name]),
                'context' => $context,
                'created_at' => now(),
            ]);

            $lockedChannel->delete();

            return $context;
        });
    }

    private function hasRemoteContent(ArticleDistribution $distribution): bool
    {
        if ($distribution->remote_id === null && $distribution->remote_url === null) {
            return false;
        }

        return (string) $distribution->action !== 'delete' || (string) $distribution->status !== 'synced';
    }

    private function auditRemoteUrl(?string $remoteUrl): ?string
    {
        if ($remoteUrl === null || trim($remoteUrl) === '') {
            return null;
        }

        $parts = parse_url($remoteUrl);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $host = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        return $scheme.'://'.$host.$port.$path;
    }

    /**
     * @param  array<string,mixed>  $impact
     */
    private function assertImpactAcknowledged(
        array $impact,
        DistributionChannelDeletionConfirmation $confirmation,
    ): void {
        if ((int) $impact['remote_content_count'] > 0 && ! $confirmation->ackRemoteContent) {
            throw new DistributionChannelDeletionBlocked('remote_content_ack_required');
        }
        if ((int) $impact['linked_task_count'] > 0 && ! $confirmation->ackTaskChanges) {
            throw new DistributionChannelDeletionBlocked('task_changes_ack_required');
        }
        if ((int) $impact['secret_count'] > 0 && ! $confirmation->ackCredentials) {
            throw new DistributionChannelDeletionBlocked('credentials_ack_required');
        }
        if (! $confirmation->ackHistory) {
            throw new DistributionChannelDeletionBlocked('history_ack_required');
        }
    }
}
