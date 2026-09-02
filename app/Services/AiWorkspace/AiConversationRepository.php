<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Support\AdminWeb;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AiConversationRepository
{
    private const GENERATED_TITLE_MAX_LENGTH = 15;

    private const GENERATION_PENDING = 'pending';

    public function create(Admin $admin, ?string $title = null): AiConversation
    {
        return AiConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => $admin->getMorphClass(),
            'participant_id' => $admin->getKey(),
            'title' => Str::limit(trim((string) $title) ?: $this->defaultTitle(), 80, ''),
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
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    /** @return Collection<int,AiConversation> */
    public function listRecentForAdmin(
        Admin $admin,
        int $limit,
        ?DateTimeInterface $boundaryTime = null,
        bool $includeSameTimestamp = false,
        ?string $beforeId = null,
    ): Collection {
        return AiConversation::query()
            ->select(['id', 'title', 'updated_at'])
            ->where('participant_type', $admin->getMorphClass())
            ->where('participant_id', $admin->getKey())
            ->whereNull('archived_at')
            ->when($boundaryTime !== null, function (Builder $query) use ($boundaryTime, $includeSameTimestamp, $beforeId): void {
                $query->where(function (Builder $query) use ($boundaryTime, $includeSameTimestamp, $beforeId): void {
                    $query->where('updated_at', '<', $boundaryTime);
                    if ($includeSameTimestamp) {
                        $query->orWhere('updated_at', '=', $boundaryTime);
                    } elseif ($beforeId !== null) {
                        $query->orWhere(function (Builder $query) use ($boundaryTime, $beforeId): void {
                            $query->where('updated_at', '=', $boundaryTime)
                                ->where('id', '<', $beforeId);
                        });
                    }
                });
            })
            ->latest('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(51, $limit)))
            ->get();
    }

    public function append(
        AiConversation $conversation,
        string $role,
        string $content,
        array $meta = [],
        array $usage = [],
    ): AiConversationMessage {
        return DB::transaction(function () use ($conversation, $role, $content, $meta, $usage): AiConversationMessage {
            $lockedConversation = AiConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedConversation->archived_at !== null) {
                throw (new ModelNotFoundException)->setModel(AiConversation::class, [$conversation->getKey()]);
            }

            $message = $this->newMessage($lockedConversation, $role, $content, $meta, $usage);
            $message->save();
            $lockedConversation->touch();
            $conversation->setRawAttributes($lockedConversation->getAttributes(), true);

            return $message;
        });
    }

    /**
     * @return array{message:AiConversationMessage,generation_id:string}
     */
    public function startGeneration(AiConversation $conversation, string $content): array
    {
        return DB::transaction(function () use ($conversation, $content): array {
            $lockedConversation = AiConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedConversation->archived_at !== null) {
                throw (new ModelNotFoundException)->setModel(AiConversation::class, [$conversation->getKey()]);
            }

            $latestUserMessage = AiConversationMessage::query()
                ->where('conversation_id', $lockedConversation->getKey())
                ->where('role', 'user')
                ->latest('created_at')
                ->latest('id')
                ->first();
            if ($latestUserMessage instanceof AiConversationMessage
                && ($latestUserMessage->meta['workspace_generation_state'] ?? null) === self::GENERATION_PENDING
                && $latestUserMessage->created_at?->isAfter(now()->subSeconds($this->generationLeaseSeconds()))) {
                throw new RuntimeException(__('admin.ai_workspace.conversation_busy'));
            }
            if ($latestUserMessage instanceof AiConversationMessage
                && ($latestUserMessage->meta['workspace_generation_state'] ?? null) === self::GENERATION_PENDING) {
                $latestUserMessage->forceFill([
                    'meta' => [...($latestUserMessage->meta ?? []), 'workspace_generation_state' => 'expired'],
                ])->save();
            }

            $isFirstMessage = ! AiConversationMessage::query()
                ->where('conversation_id', $lockedConversation->getKey())
                ->exists();
            $canGenerateTitle = $isFirstMessage && $this->usesDefaultTitle((string) $lockedConversation->title);
            $generationId = (string) Str::uuid7();
            $message = $this->newMessage($lockedConversation, 'user', $content, [
                'workspace_generation_id' => $generationId,
                'workspace_generation_state' => self::GENERATION_PENDING,
                ...($canGenerateTitle ? ['auto_title_pending' => true] : []),
            ]);
            $message->save();

            if ($canGenerateTitle) {
                $lockedConversation->forceFill(['title' => $this->fallbackTitle($content)])->save();
            } else {
                $lockedConversation->touch();
            }
            $conversation->setRawAttributes($lockedConversation->getAttributes(), true);

            return ['message' => $message, 'generation_id' => $generationId];
        });
    }

    public function completeGeneration(
        AiConversation $conversation,
        string $generationId,
        string $content,
        array $meta = [],
        array $usage = [],
        ?callable $beforePersist = null,
    ): ?AiConversationMessage {
        return DB::transaction(function () use ($conversation, $generationId, $content, $meta, $usage, $beforePersist): ?AiConversationMessage {
            $lockedConversation = AiConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedConversation->archived_at !== null) {
                return null;
            }

            $userMessage = $this->pendingGenerationMessage($lockedConversation, $generationId);
            if (! $userMessage instanceof AiConversationMessage) {
                return null;
            }

            if ($beforePersist !== null) {
                $beforePersist();
            }

            $message = $this->newMessage($lockedConversation, 'assistant', $content, $meta, $usage);
            $message->save();
            $userMessage->forceFill([
                'meta' => [...($userMessage->meta ?? []), 'workspace_generation_state' => 'completed'],
            ])->save();
            $lockedConversation->touch();
            $conversation->setRawAttributes($lockedConversation->getAttributes(), true);

            return $message;
        });
    }

    public function finishGeneration(AiConversation $conversation, string $generationId, string $state): void
    {
        DB::transaction(function () use ($conversation, $generationId, $state): void {
            $lockedConversation = AiConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();
            if (! $lockedConversation instanceof AiConversation) {
                return;
            }

            $userMessage = $this->pendingGenerationMessage($lockedConversation, $generationId);
            if (! $userMessage instanceof AiConversationMessage) {
                return;
            }

            $userMessage->forceFill([
                'meta' => [...($userMessage->meta ?? []), 'workspace_generation_state' => $state],
            ])->save();
        });
    }

    private function newMessage(
        AiConversation $conversation,
        string $role,
        string $content,
        array $meta = [],
        array $usage = [],
    ): AiConversationMessage {
        return new AiConversationMessage([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->getKey(),
            'participant_type' => $conversation->participant_type,
            'participant_id' => $conversation->participant_id,
            'agent' => 'GEOFlow',
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => $usage,
            'meta' => $meta,
            'approval_state' => null,
        ]);
    }

    public function appendUserAndGenerateTitle(AiConversation $conversation, string $content): AiConversationMessage
    {
        return DB::transaction(function () use ($conversation, $content): AiConversationMessage {
            $lockedConversation = AiConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $isFirstMessage = ! AiConversationMessage::query()
                ->where('conversation_id', $lockedConversation->getKey())
                ->exists();
            $canGenerateTitle = $isFirstMessage && $this->usesDefaultTitle((string) $lockedConversation->title);
            $message = $this->newMessage($lockedConversation, 'user', $content, $canGenerateTitle
                ? ['auto_title_pending' => true]
                : []);
            $message->save();

            if ($canGenerateTitle) {
                $lockedConversation->forceFill([
                    'title' => $this->fallbackTitle($content),
                ])->save();
            } else {
                $lockedConversation->touch();
            }
            $conversation->setRawAttributes($lockedConversation->getAttributes(), true);

            return $message;
        });
    }

    private function fallbackTitle(string $content): string
    {
        $title = Str::squish(strip_tags($content));
        if ($title === '' || $this->isLowInformationTitle($title)) {
            return __('admin.ai_workspace.casual_conversation_title');
        }

        return Str::substr($title, 0, self::GENERATED_TITLE_MAX_LENGTH);
    }

    private function isLowInformationTitle(string $title): bool
    {
        $compact = mb_strtolower((string) preg_replace('/[\p{P}\p{S}\s]+/u', '', $title));
        if ($compact === '') {
            return true;
        }

        return preg_match('/^(?:(?:你+好+)|(?:您+好+)|(?:嗨+)|(?:哈+喽+)|(?:在+吗+)|(?:hello+)|(?:hi+))+$/iu', $compact) === 1;
    }

    /** @param array<string,mixed> $meta */
    public function saveRunResponse(AiConversation $conversation, string $runId, string $content, array $meta = []): AiConversationMessage
    {
        return DB::transaction(function () use ($conversation, $runId, $content, $meta): AiConversationMessage {
            $lockedConversation = AiConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $message = AiConversationMessage::query()->find($runId);
            if ($message instanceof AiConversationMessage
                && ((string) $message->conversation_id !== (string) $lockedConversation->getKey() || (string) $message->role !== 'assistant')) {
                throw new RuntimeException(__('admin.ai_workspace.run_message_conflict'));
            }
            $message ??= new AiConversationMessage(['id' => $runId]);
            $message->fill([
                'conversation_id' => $lockedConversation->getKey(),
                'participant_type' => $lockedConversation->participant_type,
                'participant_id' => $lockedConversation->participant_id,
                'agent' => 'GEOFlow',
                'role' => 'assistant',
                'content' => $content,
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => ['run_id' => $runId] + $meta,
                'approval_state' => null,
            ]);
            $message->save();
            $lockedConversation->touch();
            $conversation->setRawAttributes($lockedConversation->getAttributes(), true);

            return $message;
        });
    }

    public function archive(Admin $admin, string $id): AiConversation
    {
        return DB::transaction(function () use ($admin, $id): AiConversation {
            $conversation = AiConversation::query()
                ->whereKey($id)
                ->where('participant_type', $admin->getMorphClass())
                ->where('participant_id', $admin->getKey())
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->firstOrFail();
            $conversation->forceFill(['archived_at' => now()])->save();

            return $conversation;
        });
    }

    public function rename(Admin $admin, string $id, string $title): AiConversation
    {
        $conversation = $this->findForAdmin($admin, $id);
        $conversation->forceFill([
            'title' => Str::limit(trim($title), 120, ''),
        ])->save();

        return $conversation;
    }

    private function pendingGenerationMessage(AiConversation $conversation, string $generationId): ?AiConversationMessage
    {
        $message = AiConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('role', 'user')
            ->latest('created_at')
            ->latest('id')
            ->first();

        return $message instanceof AiConversationMessage
            && ($message->meta['workspace_generation_id'] ?? null) === $generationId
            && ($message->meta['workspace_generation_state'] ?? null) === self::GENERATION_PENDING
                ? $message
                : null;
    }

    private function generationLeaseSeconds(): int
    {
        return max(30, (int) config('ai-workspace.conversation_generation_lease_seconds', 180));
    }

    private function defaultTitle(): string
    {
        return __('admin.ai_workspace.new_conversation_default');
    }

    private function usesDefaultTitle(string $title): bool
    {
        $defaultTitles = collect(array_keys(AdminWeb::supportedLocales()))
            ->map(static fn (string $locale): string => (string) __('admin.ai_workspace.new_conversation_default', [], $locale))
            ->push('新对话')
            ->unique()
            ->all();

        return in_array($title, $defaultTitles, true);
    }
}
