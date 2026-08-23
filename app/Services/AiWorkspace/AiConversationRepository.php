<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class AiConversationRepository
{
    public function create(Admin $admin, ?string $title = null): AiConversation
    {
        return AiConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => $admin->getMorphClass(),
            'participant_id' => $admin->getKey(),
            'title' => Str::limit(trim((string) $title) ?: '新对话', 80, ''),
        ]);
    }

    public function findForAdmin(Admin $admin, string $id, bool $includeArchived = false): AiConversation
    {
        return AiConversation::query()
            ->whereKey($id)
            ->where('participant_type', $admin->getMorphClass())
            ->where('participant_id', $admin->getKey())
            ->when(! $includeArchived, static fn ($query) => $query->whereNull('archived_at'))
            ->firstOrFail();
    }

    /** @return Collection<int,AiConversation> */
    public function listForAdmin(Admin $admin, int $limit = 30): Collection
    {
        return AiConversation::query()
            ->where('participant_type', $admin->getMorphClass())
            ->where('participant_id', $admin->getKey())
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    public function append(AiConversation $conversation, string $role, string $content, array $meta = []): AiConversationMessage
    {
        $message = new AiConversationMessage([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->getKey(),
            'participant_type' => $conversation->participant_type,
            'participant_id' => $conversation->participant_id,
            'agent' => 'GEOHub',
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => $meta,
            'approval_state' => null,
        ]);
        $message->save();
        $conversation->touch();

        return $message;
    }

    public function archive(Admin $admin, string $id): AiConversation
    {
        $conversation = $this->findForAdmin($admin, $id);
        $conversation->forceFill(['archived_at' => now()])->save();

        return $conversation;
    }
}
