<?php

namespace App\Services\AiWorkspace;

use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Generator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class AdminHelpAnswerStream
{
    public function __construct(
        private AdminHelpKnowledgeCatalog $catalog,
        private AdminHelpFeatureRegistry $features,
        private AdminHelpKnowledgeRetriever $knowledge,
        private AdminHelpMediaSelector $media,
        private AdminHelpQueryContextResolver $queryContext,
        private AiConversationRepository $conversations,
        private AiWorkspaceModelReadiness $readiness,
        private AdminHelpResponder $responder,
        private AiWorkspaceExecutionAccessGuard $executionGuard,
        private AiExecutionErrorSanitizer $errorSanitizer,
    ) {}

    public function respond(Admin $admin, AiConversation $conversation, string $question): StreamedResponse
    {
        $question = trim($question);

        return response()->eventStream(
            fn (): Generator => $this->events($admin, $conversation, $question),
            [
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ],
            null,
        );
    }

    /**
     * @return Generator<int, StreamedEvent, mixed, void>
     */
    private function events(
        Admin $admin,
        AiConversation $conversation,
        string $question,
    ): Generator {
        $generationId = null;
        try {
            try {
                $generation = $this->conversations->startGeneration($conversation, $question);
            } catch (ModelNotFoundException) {
                yield $this->errorEvent(
                    'conversation_closed',
                    __('admin.ai_workspace.conversation_closed'),
                    [],
                    [],
                    false,
                );

                return;
            } catch (RuntimeException $exception) {
                yield $this->errorEvent('conversation_busy', $exception->getMessage(), [], [], false);

                return;
            }

            $userMessage = $generation['message'];
            $generationId = $generation['generation_id'];
            $history = $this->history($conversation, (string) $userMessage->getKey());
            $queryContext = $this->queryContext->resolve($conversation, (string) $userMessage->getKey(), $question);
            yield from $this->generationEvents(
                $admin,
                $conversation,
                $question,
                $history,
                (bool) ($userMessage->meta['auto_title_pending'] ?? false),
                $generationId,
                $queryContext,
            );
        } catch (Throwable $exception) {
            report(new RuntimeException($this->errorSanitizer->sanitize($exception)));
            yield $this->errorEvent(
                'conversation_unavailable',
                __('admin.ai_workspace.workspace_internal_error'),
                [],
                [],
                $generationId !== null,
            );
        } finally {
            if ($generationId !== null) {
                $this->conversations->finishGeneration(
                    $conversation,
                    $generationId,
                    connection_aborted() ? 'cancelled' : 'failed',
                );
            }
        }
    }

    /**
     * @param  list<mixed>  $history
     * @param  array{retrieval_query:string,previous_user_question:?string,previous_sources:list<array<string,mixed>>,followup_expanded:bool}  $queryContext
     * @return Generator<int, StreamedEvent, mixed, void>
     */
    private function generationEvents(
        Admin $admin,
        AiConversation $conversation,
        string $question,
        array $history,
        bool $emitLocalTitle,
        string $generationId,
        array $queryContext,
    ): Generator {
        $executionContext = $this->executionGuard->directContext(
            $admin,
            requestId: 'ai-workspace-generation:'.$generationId,
        );
        if ($emitLocalTitle) {
            yield $this->event('title', ['title' => (string) $conversation->title]);
        }

        yield $this->event('status', [
            'stage' => 'preparing',
            'label' => __('admin.ai_workspace.status_preparing'),
        ]);

        $retrievalQuery = (string) ($queryContext['retrieval_query'] ?? $question);
        $entries = $this->catalog->search($admin, $retrievalQuery);
        $retrievedKnowledge = $this->knowledge->retrieve($admin, $retrievalQuery, $entries);
        $relatedMedia = $this->media->select($admin, $retrievedKnowledge['sources']);
        $relatedFeatures = collect([
            ...$this->features->relatedFeatures($admin, $retrievedKnowledge['related_route_names']),
            ...$this->catalog->relatedFeatures($admin, $entries),
        ])->unique('id')->take(3)->values()->all();
        $suggestions = $this->catalog->suggestions($entries, $question);

        try {
            $readiness = $this->readiness->status($executionContext);
        } catch (Throwable $exception) {
            report(new RuntimeException($this->errorSanitizer->sanitize($exception)));
            $readiness = ['ready' => false, 'reason' => __('admin.ai_workspace.ai_unavailable')];
        }
        if (! (bool) config('ai-workspace.runtime_enabled', false) || ! $readiness['ready']) {
            yield $this->errorEvent(
                'ai_unavailable',
                (string) ($readiness['reason'] ?? __('admin.ai_workspace.ai_unavailable')),
                $relatedFeatures,
                $suggestions,
            );

            return;
        }

        $knowledgeContext = (string) $retrievedKnowledge['context'];
        $answer = '';
        $firstDeltaSent = false;
        $result = null;

        try {
            $stream = $this->responder->stream($question, $knowledgeContext, $history, $executionContext);
            foreach ($stream as $responseEvent) {
                if (connection_aborted()) {
                    return;
                }

                if (is_array($responseEvent) && ($responseEvent['type'] ?? null) === 'status') {
                    yield $this->statusEvent((string) ($responseEvent['stage'] ?? 'generating'), $responseEvent);

                    continue;
                }

                $delta = is_array($responseEvent)
                    ? (string) (($responseEvent['type'] ?? null) === 'delta' ? ($responseEvent['content'] ?? '') : '')
                    : (string) $responseEvent;
                if ($delta === '') {
                    continue;
                }

                $this->assertModelReceiptCurrent(
                    $executionContext,
                    is_array($responseEvent) ? ($responseEvent['completion_receipt'] ?? null) : null,
                );
                $answer .= $delta;
                if (! $firstDeltaSent) {
                    $firstDeltaSent = true;
                    yield $this->event('delta', ['content' => $delta]);

                    continue;
                }

                yield $this->event('delta', ['content' => $delta]);
            }
            $result = $stream->getReturn();
        } catch (Throwable $exception) {
            report(new RuntimeException($this->errorSanitizer->sanitize($exception)));
            yield $this->errorEvent(
                trim($answer) === '' ? 'ai_unavailable' : 'generation_interrupted',
                trim($answer) === '' ? __('admin.ai_workspace.ai_unavailable') : __('admin.ai_workspace.generation_interrupted'),
                $relatedFeatures,
                $suggestions,
            );

            return;
        }

        $completedAnswer = is_array($result)
            ? trim((string) ($result['answer'] ?? $answer))
            : (trim((string) $result) ?: trim($answer));
        if (! $firstDeltaSent && $completedAnswer !== '') {
            $answer = $completedAnswer;
            yield $this->event('delta', ['content' => $completedAnswer]);
        }

        if ($completedAnswer === '') {
            yield $this->errorEvent('empty_answer', __('admin.ai_workspace.empty_answer'), $relatedFeatures, $suggestions);

            return;
        }

        if (connection_aborted()) {
            return;
        }

        $generationMeta = is_array($result) && is_array($result['meta'] ?? null)
            ? $this->safeGenerationMeta($result['meta'])
            : [];
        $usage = is_array($result) && is_array($result['usage'] ?? null)
            ? $this->safeUsage($result['usage'])
            : [];
        $completionReceipt = is_array($result) ? ($result['completion_receipt'] ?? null) : null;
        $usageDelivery = is_array($result) ? ($result['usage_delivery'] ?? null) : null;
        try {
            $message = $this->conversations->completeGeneration($conversation, $generationId, $completedAnswer, [
                'knowledge_entry_ids' => array_values(array_map(static fn (array $entry): string => (string) $entry['id'], $entries)),
                'knowledge_sources' => $retrievedKnowledge['sources'],
                'knowledge_health' => $retrievedKnowledge['knowledge_health'],
                'related_media' => $relatedMedia,
                'related_features' => $relatedFeatures,
                'suggestions' => $suggestions,
                'generation' => $generationMeta,
                'retrieval' => [
                    'mode' => $retrievedKnowledge['retrieval_mode'],
                    'latency_ms' => $retrievedKnowledge['retrieval_latency_ms'],
                    'fallback_reason' => $retrievedKnowledge['fallback_reason'],
                    'evidence_count' => $retrievedKnowledge['evidence_count'],
                    'followup_expanded' => (bool) ($queryContext['followup_expanded'] ?? false),
                ],
            ], $usage, fn () => $this->assertModelReceiptCurrent($executionContext, $completionReceipt));
        } catch (AiModelAccessException $exception) {
            if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                $usageDelivery->revoked($exception->getErrorCode());
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                $usageDelivery->discarded('ai_result_not_committed');
            }

            throw $exception;
        }
        if (! $message instanceof AiConversationMessage) {
            if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
                $usageDelivery->discarded('ai_result_not_committed');
            }
            yield $this->errorEvent(
                'conversation_closed',
                __('admin.ai_workspace.conversation_closed'),
                [],
                [],
            );

            return;
        }
        if ($usageDelivery instanceof AiWorkspaceModelUsageDelivery) {
            $usageDelivery->succeeded();
        }
        $conversation->refresh();

        Log::info('AI Workspace system knowledge retrieval completed.', [
            'retrieval_mode' => $retrievedKnowledge['retrieval_mode'],
            'retrieval_latency_ms' => $retrievedKnowledge['retrieval_latency_ms'],
            'fallback_reason' => $retrievedKnowledge['fallback_reason'],
            'evidence_count' => $retrievedKnowledge['evidence_count'],
            'knowledge_version' => $retrievedKnowledge['sources'][0]['official_version'] ?? null,
            'entry_count' => count($relatedFeatures),
            'media_count' => count($relatedMedia),
        ]);

        yield $this->event('done', [
            'message_id' => (string) $message->getKey(),
            'conversation_title' => (string) $conversation->title,
            'related_features' => $relatedFeatures,
            'suggestions' => $suggestions,
            'knowledge_sources' => $retrievedKnowledge['sources'],
            'knowledge_health' => $retrievedKnowledge['knowledge_health'],
            'related_media' => $relatedMedia,
            'generation' => $generationMeta,
        ]);
    }

    private function assertModelReceiptCurrent(
        AiWorkspaceExecutionContext $context,
        mixed $receipt,
    ): void {
        if ($receipt instanceof AiWorkspaceModelExecutionReceipt) {
            $this->executionGuard->assertReceiptCurrent($context, $receipt);

            return;
        }

        if ($this->responder instanceof AiWorkspaceModelRuntime || ! app()->environment('testing')) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        $this->executionGuard->assertCurrent($context);
    }

    /** @param array<string, mixed> $runtimeEvent */
    private function statusEvent(string $stage, array $runtimeEvent): StreamedEvent
    {
        $label = match ($stage) {
            'connected' => __('admin.ai_workspace.status_connected'),
            'reasoning', 'generating' => __('admin.ai_workspace.status_model_generating'),
            default => __('admin.ai_workspace.status_preparing'),
        };

        return $this->event('status', array_filter([
            'stage' => $stage,
            'label' => $label,
            'provider' => isset($runtimeEvent['provider']) ? (string) $runtimeEvent['provider'] : null,
            'model' => isset($runtimeEvent['model']) ? (string) $runtimeEvent['model'] : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @param array<string,mixed> $meta @return array<string,int|string|bool|null> */
    private function safeGenerationMeta(array $meta): array
    {
        $safe = [];
        foreach ([
            'model_started_at', 'provider_first_event_ms', 'ttft_ms', 'total_ms',
            'attempts', 'fallback_count', 'degraded_count', 'provider', 'model',
            'finish_reason', 'late_stream_close',
        ] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value)) {
                $safe[$key] = Str::limit($this->errorSanitizer->sanitize($value, ''), 160, '');
            } elseif (is_int($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @param array<string,mixed> $usage @return array<string,int> */
    private function safeUsage(array $usage): array
    {
        $safe = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $safe[$key] = max(0, (int) $usage[$key]);
            }
        }

        return $safe;
    }

    /** @return list<mixed> */
    private function history(AiConversation $conversation, string $excludedMessageId): array
    {
        $remainingCharacters = max(1000, (int) config('ai-workspace.conversation_history_char_budget', 10_000));
        $messages = AiConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereKeyNot($excludedMessageId)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('created_at')
            ->latest('id')
            ->limit(40)
            ->get();
        $history = [];

        foreach ($messages as $message) {
            $content = trim((string) $message->content);
            if ($content === '' || Str::length($content) > $remainingCharacters) {
                continue;
            }
            $history[] = (string) $message->role === 'assistant'
                ? new AssistantMessage($content)
                : new UserMessage($content);
            $remainingCharacters -= Str::length($content);
            if ($remainingCharacters <= 0) {
                break;
            }
        }

        return array_reverse($history);
    }

    /** @param array<string, mixed> $data */
    private function event(string $name, array $data): StreamedEvent
    {
        return new StreamedEvent($name, $data);
    }

    /**
     * @param  list<array<string, mixed>>  $relatedFeatures
     * @param  list<string>  $suggestions
     */
    private function errorEvent(
        string $code,
        string $message,
        array $relatedFeatures,
        array $suggestions,
        bool $persisted = true,
    ): StreamedEvent {
        return $this->event('error', [
            'code' => $code,
            'message' => $message,
            'related_features' => $relatedFeatures,
            'suggestions' => $suggestions,
            'persisted' => $persisted,
        ]);
    }
}
