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
use App\Models\KnowledgeMediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;

final class PruneAiWorkspaceDataCommand extends Command
{
    protected $signature = 'geoflow:prune-ai-workspace
        {--days= : Retention period in days}
        {--dry-run : Report the records that are eligible without deleting or redacting data}';

    protected $description = 'Remove expired AI workspace conversation and payload data';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('ai-workspace.retention_days', 90)));
        $cutoff = now()->subDays($days);
        $workspaceConversationIds = AiConversation::query()->select('id');
        if ((bool) $this->option('dry-run')) {
            $messageCount = ConversationMessage::query()
                ->whereIn('conversation_id', $workspaceConversationIds)
                ->where('created_at', '<', $cutoff)
                ->count();
            $runCount = AiWorkspaceRun::query()
                ->whereIn('state', AiWorkspaceRun::TERMINAL_STATES)
                ->where('finished_at', '<', $cutoff)
                ->whereNull('payload_pruned_at')
                ->count();
            $mediaCount = $this->pruneExpiredKnowledgeMedia($cutoff, true);
            $this->info(sprintf(
                'Dry run: %d messages, %d run payloads, and %d inactive knowledge media assets are eligible.',
                $messageCount,
                $runCount,
                $mediaCount,
            ));

            return self::SUCCESS;
        }

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
            ->where('updated_at', '<', $cutoff)
            ->update(['title' => '已清理会话', 'updated_at' => now()]);

        $mediaCount = $this->pruneExpiredKnowledgeMedia($cutoff);

        $this->info(sprintf(
            'Pruned %d messages, %d run payloads, and %d inactive knowledge media assets.',
            $messageCount,
            $runCount,
            $mediaCount,
        ));

        return self::SUCCESS;
    }

    private function pruneExpiredKnowledgeMedia(\DateTimeInterface $messageCutoff, bool $dryRun = false): int
    {
        if (! Schema::hasTable('knowledge_media_assets')) {
            return 0;
        }

        $referencedIds = [];
        ConversationMessage::query()
            ->whereIn('conversation_id', AiConversation::query()->select('id'))
            ->where('created_at', '>=', $messageCutoff)
            ->select(['id', 'meta'])
            ->orderBy('id')
            ->chunk(200, function ($messages) use (&$referencedIds): void {
                foreach ($messages as $message) {
                    $relatedMedia = is_array($message->meta['related_media'] ?? null)
                        ? $message->meta['related_media']
                        : [];
                    foreach ($relatedMedia as $media) {
                        $id = is_array($media) ? (int) ($media['id'] ?? 0) : 0;
                        if ($id > 0) {
                            $referencedIds[$id] = true;
                        }
                    }
                }
            });

        $officialHashes = $this->currentOfficialMediaHashes();
        $mediaCutoff = now()->setTimestamp($messageCutoff->getTimestamp())->subDays(7);
        $count = 0;
        KnowledgeMediaAsset::query()
            ->where('is_active', false)
            ->where('updated_at', '<', $mediaCutoff)
            ->when($referencedIds !== [], static fn ($query) => $query->whereNotIn('id', array_keys($referencedIds)))
            ->when($officialHashes !== [], static fn ($query) => $query->whereNotIn('content_hash', $officialHashes))
            ->orderBy('id')
            ->chunkById(100, function ($assets) use (&$count, $dryRun): void {
                foreach ($assets as $asset) {
                    if ($dryRun) {
                        $count++;

                        continue;
                    }
                    $paths = array_values(array_unique(array_filter([
                        (string) $asset->storage_path,
                        (string) $asset->thumbnail_path,
                    ])));
                    DB::transaction(static function () use ($asset): void {
                        $asset->delete();
                    });

                    foreach ($paths as $path) {
                        $stillReferenced = KnowledgeMediaAsset::query()
                            ->where(static fn ($query) => $query
                                ->where('storage_path', $path)
                                ->orWhere('thumbnail_path', $path))
                            ->exists();
                        if (str_starts_with($path, 'ai-workspace-knowledge-media/')
                            && ! str_contains($path, '..')
                            && ! $stillReferenced) {
                            Storage::disk('local')->delete($path);
                        }
                    }
                    $count++;
                }
            });

        return $count;
    }

    /** @return list<string> */
    private function currentOfficialMediaHashes(): array
    {
        $manifestPath = resource_path('knowledge/ai-workspace/media/manifest.json');
        if (! is_readable($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        return collect(is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [])
            ->map(static fn (mixed $asset): string => is_array($asset)
                ? Str::after(trim((string) ($asset['content_hash'] ?? '')), 'sha256:')
                : '')
            ->filter(static fn (string $hash): bool => preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
