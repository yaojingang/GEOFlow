<?php

namespace App\Services\AiWorkspace;

use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Support\AdminActivityLogger;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Generator;
use Illuminate\Http\StreamedEvent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class TaskCreationAnswerStream
{
    public function __construct(
        private AiConversationRepository $conversations,
        private TaskCreationCatalog $catalog,
        private TaskCreationFlow $flow,
        private AiWorkspaceModelRuntime $runtime,
        private AiWorkspaceExecutionAccessGuard $access,
        private AiWorkspaceModelReadiness $readiness,
        private AiExecutionErrorSanitizer $errors,
    ) {}

    public function respond(Admin $admin, AiConversation $conversation, string $prompt, array $input): StreamedResponse
    {
        return response()->eventStream(fn () => $this->events($admin, $conversation, trim($prompt), $input), [
            'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no',
        ], null);
    }

    private function events(Admin $admin, AiConversation $conversation, string $prompt, array $input): Generator
    {
        $generationId = null;
        try {
            $this->assertRuntimeBoundary();
            $generation = $this->conversations->startGeneration($conversation, $prompt);
            $generationId = $generation['generation_id'];
            yield $this->event('title', ['title' => $conversation->title]);
            yield $this->event('status', ['stage' => 'preparing', 'label' => __('ai-task.preparing')]);
            $context = $this->access->directContext($admin, requestId: 'task-draft:'.$generationId);
            $previous = $conversation->task_draft;
            $expected = $previous;
            $staleInput = isset($input['task_draft_id']) && (! is_array($previous)
                || $input['task_draft_id'] !== $previous['id']
                || (int) ($input['task_draft_revision'] ?? 0) !== $previous['revision']);
            $confirm = $this->flow->isConfirmation($prompt) && is_array($previous);
            $cancel = $this->flow->isCancellation($prompt) && is_array($previous);
            $choice = $input['task_choice'] ?? null;
            if (! is_array($previous) || (! $staleInput && ! $confirm && ! $cancel && $choice === null && in_array($previous['status'], ['created', 'cancelled'], true))) {
                $previous = $this->flow->emptyDraft();
            }
            $persist = function (array $response = [], ?AiWorkspaceModelExecutionReceipt $receipt = null) use ($admin, $conversation, $generationId, $context, $previous, $expected, $staleInput, $confirm, $cancel, $choice, $input): ?AiConversationMessage {
                if (connection_aborted()) {
                    return null;
                }

                return $this->conversations->completeGeneration(
                    $conversation, $generationId, '',
                    beforePersist: function () use ($context, $receipt): void {
                        $this->assertRuntimeBoundary();
                        $this->access->assertCurrent($context);
                        if ($receipt !== null) {
                            $this->access->assertReceiptCurrent($context, $receipt);
                        }
                    },
                    prepareMessage: function (AiConversation $locked) use ($admin, $previous, $expected, $staleInput, $confirm, $cancel, $choice, $response, $input): array {
                        $currentAdmin = Admin::query()->whereKey($admin->id)->lockForUpdate()->first();
                        if (! $currentAdmin || $currentAdmin->status !== 'active' || (int) $currentAdmin->auth_version !== (int) $admin->auth_version
                            || $locked->participant_type !== $admin->getMorphClass() || (int) $locked->participant_id !== (int) $admin->id) {
                            throw new RuntimeException(__('ai-task.access_changed'));
                        }
                        if ($locked->task_draft !== $expected) {
                            throw new RuntimeException(__('ai-task.stale'));
                        }
                        $catalog = $this->catalog->forAdmin($currentAdmin);
                        if ($staleInput || (($choice !== null || ($cancel && isset($input['task_draft_id']))) && (($input['task_draft_id'] ?? '') !== $previous['id']
                            || (int) ($input['task_draft_revision'] ?? 0) !== $previous['revision']
                            || ! in_array($previous['status'], ['collecting', 'ready'], true)))) {
                            [$draft, $content] = [$previous, __('ai-task.stale')];
                        } else {
                            if ($choice !== null) {
                                $value = $choice['field'] === 'knowledge_base_ids' ? [(int) $choice['id']] : (int) $choice['id'];
                                $response = ['intent' => 'collect', 'reply' => __('ai-task.choice_saved'), 'draft' => [...$previous['data'], $choice['field'] => $value]];
                            } elseif ($cancel) {
                                $response = ['intent' => 'cancel', 'reply' => __('ai-task.cancelled'), 'draft' => $previous['data']];
                            }
                            [$draft, $content] = $confirm
                                ? $this->flow->confirm($currentAdmin, $previous, $input, $catalog)
                                : $this->flow->collect($previous, $response, $catalog, $choice['field'] ?? null);
                        }
                        $card = $this->flow->card($draft, $catalog);
                        $locked->forceFill(['task_draft' => $draft])->save();
                        if ($draft['status'] === 'created' && $previous['status'] !== 'created') {
                            AdminActivityLogger::log($currentAdmin, 'ai_workspace.task.create', [
                                'request_method' => 'POST', 'page' => request()->path(),
                                'target_type' => 'task', 'target_id' => $draft['task_id'],
                                'details' => ['task_id' => $draft['task_id'], 'conversation_id' => $locked->id, 'success' => true],
                            ]);
                        }

                        return ['content' => $content, 'meta' => ['task_card' => $card]];
                    },
                );
            };
            if ($staleInput || $confirm || $cancel || $choice !== null) {
                $message = $persist();
            } else {
                if (! $this->readiness->status($context)['ready']) {
                    throw new AiWorkspaceRuntimeGuardException(__('admin.ai_workspace.ai_unavailable'));
                }
                $catalog = $this->catalog->forAdmin($admin);
                $contextData = json_encode(['locale' => app()->getLocale(), 'draft' => ['need_review' => 1, ...$previous['data']], 'catalog' => $catalog], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (mb_strlen($contextData) > 48000) {
                    throw new AiWorkspaceRuntimeGuardException(__('ai-task.catalog_large'));
                }
                $message = $this->runtime->draftTask($prompt, $contextData, $this->history($conversation, $generation['message']->id), $context, $persist);
            }
            if (! $message instanceof AiConversationMessage) {
                yield $this->event('error', ['code' => 'task_draft_interrupted', 'message' => __('ai-task.interrupted')]);

                return;
            }
            yield $this->event('delta', ['content' => $message->content]);
            yield $this->event('done', ['message_id' => $message->id, 'task_card' => $message->meta['task_card']]);
        } catch (Throwable $exception) {
            report(new RuntimeException($this->errors->sanitize($exception)));
            yield $this->event('error', ['code' => 'task_draft_failed', 'persisted' => $generationId !== null,
                'message' => $exception instanceof AiWorkspaceRuntimeGuardException || ($generationId === null && $exception->getMessage() === __('admin.ai_workspace.conversation_busy'))
                    ? $exception->getMessage() : __('ai-task.failed')]);
        } finally {
            if ($generationId !== null) {
                $this->conversations->finishGeneration($conversation, $generationId, 'failed');
            }
        }
    }

    private function assertRuntimeBoundary(): void
    {
        $connection = config('ai.conversations.connection');
        if (! (bool) config('ai-workspace.runtime_enabled', false)
            || (is_string($connection) && $connection !== '' && $connection !== config('database.default'))) {
            throw new AiWorkspaceRuntimeGuardException(__('admin.ai_workspace.ai_unavailable'));
        }
    }

    private function history(AiConversation $conversation, string $currentMessageId): array
    {
        return AiConversationMessage::query()->where('conversation_id', $conversation->id)
            ->where('id', '!=', $currentMessageId)->latest('created_at')->latest('id')->limit(6)
            ->get(['role', 'content', 'meta'])->reverse()->map(function ($message) {
                $text = mb_substr((string) $message->content, 0, 1000);
                if ($message->role === 'assistant') {
                    $text .= json_encode($message->meta['task_card']['questions'] ?? [], JSON_UNESCAPED_UNICODE);

                    return new AssistantMessage(mb_substr($text, 0, 1800));
                }

                return new UserMessage($text);
            })->all();
    }

    private function event(string $name, array $data): StreamedEvent
    {
        return new StreamedEvent($name, $data);
    }
}
