<?php

namespace App\Console\Commands;

use App\Ai\Workspace\AiPayloadDigest;
use App\Models\AiConversation;
use App\Models\AiWorkspaceApproval;
use App\Models\AiWorkspaceArtifact;
use App\Models\AiWorkspaceExternalOperation;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceStep;
use App\Models\ArticleDistribution;
use App\Models\DistributionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\ConversationMessage;

final class PruneAiWorkspaceDataCommand extends Command
{
    protected $signature = 'geoflow:prune-ai-workspace {--days=}';

    protected $description = 'Remove expired AI workspace conversation and payload data';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('ai-workspace.retention_days', 90)));
        $cutoff = now()->subDays($days);
        $workspaceConversationIds = AiWorkspaceRun::query()->distinct()->pluck('conversation_id');
        $messageCount = ConversationMessage::query()
            ->whereIn('conversation_id', $workspaceConversationIds)
            ->where('created_at', '<', $cutoff)
            ->delete();

        $runCount = 0;
        AiWorkspaceRun::query()
            ->whereIn('state', AiWorkspaceRun::TERMINAL_STATES)
            ->where('finished_at', '<', $cutoff)
            ->whereNull('payload_pruned_at')
            ->orderBy('id')
            ->chunkById(200, function ($runs) use (&$runCount): void {
                DB::transaction(function () use ($runs, &$runCount): void {
                    $ids = $runs->pluck('id');
                    $executionKeys = AiWorkspaceExternalOperation::query()
                        ->whereIn('run_id', $ids)
                        ->whereNotNull('execution_key')
                        ->pluck('execution_key')
                        ->filter()
                        ->values();
                    AiWorkspaceStep::query()->whereIn('run_id', $ids)->update([
                        'parameters' => '[]',
                        'target_summary' => null,
                        'result_summary' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
                    AiWorkspaceArtifact::query()->whereIn('run_id', $ids)->update([
                        'name' => '已清理产物',
                        'content' => null,
                        'payload' => null,
                        'source_url' => null,
                        'expires_at' => null,
                        'updated_at' => now(),
                    ]);
                    AiWorkspaceApproval::query()->whereIn('run_id', $ids)->update([
                        'decision_reason' => null,
                        'updated_at' => now(),
                    ]);
                    AiWorkspaceExternalOperation::query()->whereIn('run_id', $ids)->update([
                        'request_payload' => null,
                        'remote_result' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
                    if ($executionKeys->isNotEmpty()) {
                        DistributionLog::query()
                            ->whereIn('event', ['site.settings.synced'])
                            ->whereIn('context->ai_workspace_execution_key', $executionKeys->all())
                            ->get()
                            ->each(function (DistributionLog $log): void {
                                $context = is_array($log->context) ? $log->context : [];
                                $executionKey = (string) ($context['ai_workspace_execution_key'] ?? '');
                                $remoteResult = (array) ($context['remote_result'] ?? []);
                                $log->forceFill(['context' => [
                                    'ai_workspace_execution_digest' => AiPayloadDigest::make(['execution_key' => $executionKey]),
                                    'remote_result_digest' => AiPayloadDigest::make($remoteResult),
                                    'refresh_count' => (int) ($context['refresh_count'] ?? 0),
                                    'payload_pruned' => true,
                                ]])->save();
                            });
                    }
                    ArticleDistribution::query()
                        ->where(function ($query) use ($ids): void {
                            foreach ($ids as $id) {
                                $query->orWhere('remote_meta->ai_workspace_guard->run_id', (string) $id);
                            }
                        })
                        ->get()
                        ->each(function (ArticleDistribution $distribution): void {
                            $meta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                            unset($meta['ai_workspace_payload']);
                            $distribution->forceFill(['remote_meta' => $meta])->save();
                        });
                    AiWorkspaceRun::query()->whereIn('id', $ids)->update([
                        'prompt' => '[已按留存策略清理]',
                        'answer' => null,
                        'candidate_capabilities' => null,
                        'known_parameters' => null,
                        'missing_parameters' => null,
                        'plan' => null,
                        'failure_message' => null,
                        'payload_pruned_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $runCount += $ids->count();
                });
            }, 'id');

        AiConversation::query()
            ->whereIn('id', $workspaceConversationIds)
            ->where('updated_at', '<', $cutoff)
            ->update(['title' => '已清理会话', 'updated_at' => now()]);

        $this->info(sprintf('Pruned %d messages and %d run payloads.', $messageCount, $runCount));

        return self::SUCCESS;
    }
}
