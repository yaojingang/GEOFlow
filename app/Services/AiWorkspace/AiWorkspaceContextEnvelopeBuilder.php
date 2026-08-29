<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiPayloadDigest;
use App\Models\AiWorkspaceApproval;
use App\Models\AiWorkspaceArtifact;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;

final class AiWorkspaceContextEnvelopeBuilder
{
    /** @return array<string,mixed> */
    public function build(AiWorkspaceRun $run): array
    {
        $records = ConversationMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();
        $last = $records->last();
        if ($last instanceof ConversationMessage
            && (string) $last->role === 'user'
            && hash_equals((string) $last->content, (string) $run->prompt)) {
            $records->pop();
        }

        $budget = max(0, (int) config('ai-workspace.conversation_history_char_budget', 24000));
        $selected = [];
        $references = [];
        $characterCount = 0;
        foreach ($records->reverse() as $message) {
            if (count($selected) >= 20 || $characterCount >= $budget) {
                break;
            }
            $remaining = min(4000, $budget - $characterCount);
            if ($remaining <= 0) {
                break;
            }
            $content = Str::limit((string) $message->content, $remaining, '');
            if ($content === '') {
                continue;
            }
            $characterCount += Str::length($content);
            array_unshift(
                $selected,
                (string) $message->role === 'assistant' ? new AssistantMessage($content) : new UserMessage($content),
            );
            array_unshift($references, [
                'id' => (string) $message->id,
                'role' => (string) $message->role,
                'digest' => hash('sha256', (string) $message->content),
            ]);
        }

        $contextRuns = AiWorkspaceRun::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('admin_id', $run->admin_id)
            ->latest('created_at')
            ->limit(12)
            ->get(['id', 'state', 'intent', 'plan_version', 'risk_level', 'prompt']);
        $contextRunIds = $contextRuns->pluck('id');
        $artifacts = AiWorkspaceArtifact::query()
            ->whereIn('run_id', $contextRunIds)
            ->where('type', '!=', 'plan_revision')
            ->latest('created_at')
            ->limit(10)
            ->get();
        $artifactReferences = $artifacts->map(static fn (AiWorkspaceArtifact $artifact): array => [
            'id' => (string) $artifact->id,
            'type' => (string) $artifact->type,
            'name' => (string) $artifact->name,
            'source_route' => $artifact->source_route,
            'content_digest' => hash('sha256', (string) $artifact->content),
        ])->values()->all();
        $openRunReferences = $contextRuns
            ->reject(static fn (AiWorkspaceRun $contextRun): bool => $contextRun->isTerminal())
            ->map(static fn (AiWorkspaceRun $contextRun): array => [
                'id' => (string) $contextRun->id,
                'state' => (string) $contextRun->state,
                'intent' => Str::limit(strip_tags((string) $contextRun->intent), 120, ''),
                'plan_version' => (int) $contextRun->plan_version,
                'risk_level' => (string) $contextRun->risk_level,
                'prompt_digest' => hash('sha256', (string) $contextRun->prompt),
            ])->values()->all();
        $approvalReferences = $contextRunIds->isEmpty()
            ? []
            : AiWorkspaceApproval::query()
                ->whereIn('run_id', $contextRunIds)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->limit(20)
                ->get(['id', 'run_id', 'capability_key', 'plan_version', 'expires_at'])
                ->map(static fn ($approval): array => [
                    'id' => (string) $approval->id,
                    'run_id' => (string) $approval->run_id,
                    'capability' => (string) $approval->capability_key,
                    'plan_version' => (int) $approval->plan_version,
                    'expires_at' => $approval->expires_at?->toISOString(),
                ])->values()->all();
        $truncated = $records->count() > count($selected);
        if ($truncated) {
            $summaryArtifact = $this->summaryArtifact($run, $records->take(max(0, $records->count() - count($selected)))->all());
            if ($summaryArtifact instanceof AiWorkspaceArtifact) {
                $artifactReferences[] = [
                    'id' => (string) $summaryArtifact->id,
                    'type' => (string) $summaryArtifact->type,
                    'name' => (string) $summaryArtifact->name,
                    'source_route' => $summaryArtifact->source_route,
                    'content_digest' => hash('sha256', (string) $summaryArtifact->content),
                ];
            }
        }

        $digest = AiPayloadDigest::make([
            'messages' => $references,
            'artifacts' => $artifactReferences,
            'open_runs' => $openRunReferences,
            'approvals' => $approvalReferences,
            'prompt_versions' => $run->prompt_versions,
        ]);

        return [
            'messages' => $selected,
            'message_references' => $references,
            'artifact_references' => $artifactReferences,
            'open_run_references' => $openRunReferences,
            'approval_references' => $approvalReferences,
            'character_count' => $characterCount,
            'truncated' => $truncated,
            'digest' => $digest,
        ];
    }

    /** @return list<UserMessage|AssistantMessage> */
    public function messages(AiWorkspaceRun $run): array
    {
        return $this->build($run)['messages'];
    }

    /** @param list<ConversationMessage> $omitted */
    private function summaryArtifact(AiWorkspaceRun $run, array $omitted): ?AiWorkspaceArtifact
    {
        if ($omitted === []) {
            return null;
        }
        $references = collect($omitted)->map(static fn (ConversationMessage $message): array => [
            'id' => (string) $message->id,
            'role' => (string) $message->role,
            'digest' => hash('sha256', (string) $message->content),
        ])->values()->all();
        $digest = AiPayloadDigest::make($references);
        $existing = $run->artifacts()->where('type', 'conversation_summary')->where('content', $digest)->first();
        if ($existing instanceof AiWorkspaceArtifact) {
            return $existing;
        }

        return $run->artifacts()->create([
            'id' => (string) Str::uuid7(),
            'step_id' => null,
            'created_by_admin_id' => $run->admin_id,
            'created_by_username_snapshot' => $run->admin_username_snapshot,
            'type' => 'conversation_summary',
            'name' => '对话上下文摘要',
            'data_classification' => 'internal',
            'content' => $digest,
            'payload' => [
                'message_references' => $references,
                'summary_mode' => 'deterministic',
                'model_snapshot' => collect((array) $run->model_snapshot)->only(['provider', 'model', 'model_id', 'prompt_version'])->all(),
                'prompt_versions' => (array) $run->prompt_versions,
            ],
            'expires_at' => now()->addDays((int) config('ai-workspace.retention_days', 90)),
        ]);
    }
}
