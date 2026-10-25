<?php

namespace App\Services\GeoFlow;

use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Title;
use App\Support\GeoFlow\PromptTestAnchors;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Throwable;

final class PromptTestService
{
    public function __construct(
        private readonly PromptTestRunStore $runs,
        private readonly DirectAdminAiExecutionGuard $guard,
        private readonly DirectAdminAiModelInvocationGateway $gateway,
        private readonly KnowledgeRetrievalService $retrieval,
        private readonly ArticleContentPromptRenderer $renderer,
        private readonly ArticleContentGenerationService $generation,
        private readonly ArticleCitationMarkerCleaner $cleaner,
        private readonly PromptTestDiagnosticService $diagnostic,
        private readonly AiUsageQuotaService $usageQuota,
    ) {}

    /** @param array<string,mixed> $payload
     * @return array{run:array<string,mixed>,lock:Lock}
     */
    public function start(Admin $admin, Prompt $prompt, array $payload): array
    {
        // Cover the total deadline, one in-flight provider timeout, and cleanup.
        $lock = $this->runs->acquire((int) $admin->id, $this->generation->providerTimeoutSeconds() * 2 + 180);
        abort_if($lock === null, 409, __('prompt_test.busy'));
        try {
            abort_if($this->runs->find($admin->id, $prompt->id, $payload['request_id']) !== null, 409, __('prompt_test.duplicate'));
            $context = $this->guard->freeze($admin, 'prompt_test', $prompt->id, requestedModelId: (int) $payload['ai_model_id']);
            $model = $this->guard->resolveModel($context)['model'];
            $diagnosticContext = $this->guard->freeze($admin, 'prompt_test_diagnostic', $prompt->id,
                requestedModelId: (int) ($payload['diagnostic_model_id'] ?? $model->id));
            $diagnosticModel = $this->guard->resolveModel($diagnosticContext)['model'];
            $title = $payload['title_source'] === 'library'
                ? (string) Title::query()->findOrFail($payload['title_id'])->title
                : trim($payload['title']);
            $knowledgeBases = KnowledgeBase::query()->whereIn('id', $payload['knowledge_base_ids'])->get(['id', 'name']);
            if ($knowledgeBases->count() !== count($payload['knowledge_base_ids'])) {
                throw ValidationException::withMessages(['knowledge_base_ids' => __('prompt_test.errors.knowledge_unavailable')]);
            }
            $content = PromptTestAnchors::normalize($payload['prompt_content']);
            $run = [
                'id' => $payload['request_id'], 'admin_id' => (int) $admin->id, 'prompt_id' => (int) $prompt->id,
                'status' => 'retrieving', 'created_at' => now()->toIso8601String(),
                'expires_at' => now()->timestamp + PromptTestRunStore::TTL,
                'deadline' => now()->timestamp + $this->generation->providerTimeoutSeconds() + 120,
                'locale' => app()->getLocale(), 'title' => $title, 'keyword' => (string) ($payload['keyword'] ?? ''),
                'prompt_content' => $content, 'prompt_hash' => hash('sha256', $content),
                'knowledge_base_ids' => array_map('intval', $payload['knowledge_base_ids']),
                'knowledge_bases' => $knowledgeBases->toArray(),
                'generation_model' => ['id' => (int) $model->id, 'name' => (string) $model->name],
                'diagnostic_model' => ['id' => (int) $diagnosticModel->id, 'name' => (string) $diagnosticModel->name],
                'generation_context' => $context, 'diagnostic_context' => $diagnosticContext,
                'content' => '', 'html' => '', 'rendered_prompt' => '', 'evidence' => [],
                'diagnosis' => null, 'diagnosis_request_id' => null, 'diagnosis_attempt_ids' => [], 'error_code' => null,
            ];
            $this->runs->save($run);

            return compact('run', 'lock');
        } catch (Throwable $exception) {
            $lock->release();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $run
     * @return iterable<string>
     */
    public function stream(array $run, Lock $lock): iterable
    {
        $invocation = null;
        $session = null;
        $stream = null;
        $providerReturned = false;
        $receivedUsage = null;
        try {
            yield $this->event('started', ['run' => $this->runs->present($run)]);
            $this->assertRunning($run);
            $bundle = $this->retrieval->retrieveContextBundleFromMany(
                $run['knowledge_base_ids'], trim($run['title']."\n".$run['keyword']), 5, 3200,
                $this->guard->assertCurrent($run['generation_context']), $run['generation_context']->requestId,
                fn () => $this->assertRunning($run),
            );
            if (trim($bundle['context']) === '') {
                throw new RuntimeException('knowledge_unavailable');
            }
            $this->assertRunning($run);
            $run['evidence'] = $bundle['evidence'];
            $run['rendered_prompt'] = $this->renderer->renderForEditor($run['title'], $run['keyword'], $run['prompt_content'], $bundle['context']);
            $invocation = $this->gateway->acquire($run['generation_context'], $this->generation->providerTimeoutSeconds() + 60);
            $this->assertRunning($run);
            $run['status'] = 'generating';
            $run['phase'] = 'waiting';
            $this->runs->save($run);
            yield $this->event('stage', ['status' => 'generating', 'phase' => 'waiting']);
            $session = $this->generation->deferredStreamWithReservation(
                $invocation->model, $run['rendered_prompt'], $invocation->reservation,
                fn () => $invocation->beginUsageAttempt($run['rendered_prompt'], 'prompt_test.generate', 'prompt_test', Prompt::class, $run['prompt_id']),
            );
            $stream = $session->stream;
            $lastPreview = 0.0;
            $finishReason = null;
            foreach ($stream as $event) {
                if ($event instanceof StreamEnd) {
                    $finishReason = $event->reason;
                    $receivedUsage = $receivedUsage?->add($event->usage) ?? $event->usage;
                }
                $this->assertRunning($run);
                $phase = $event instanceof ReasoningStart || $event instanceof ReasoningDelta ? 'reasoning'
                    : ($event instanceof TextDelta ? 'writing' : null);
                if ($phase !== null && $run['phase'] !== $phase) {
                    $run['phase'] = $phase;
                    $this->runs->save($run);
                    yield $this->event('stage', ['status' => 'generating', 'phase' => $phase]);
                }
                if (! $event instanceof TextDelta) {
                    continue;
                }
                $run['content'] .= $event->delta;
                if (mb_strlen($run['content']) > 100000) {
                    throw new RuntimeException('content_too_long');
                }
                yield $this->event('delta', ['text' => $event->delta]);
                if (microtime(true) - $lastPreview >= .3) {
                    $run['html'] = $this->html($run);
                    $this->runs->save($run);
                    yield $this->event('preview', ['html' => $run['html']]);
                    $lastPreview = microtime(true);
                }
            }
            $providerReturned = true;
            $this->assertRunning($run);
            $run['content'] = $this->cleaner->cleanContent((string) $stream->text);
            if (trim($run['content']) === '' || $finishReason !== 'stop') {
                throw new RuntimeException('incomplete');
            }
            $run['html'] = $this->html($run);
            $run['status'] = 'article_ready';
            $session->complete();
            $this->guard->assertModelCurrent($run['generation_context'], $invocation->model);
            $this->runs->save($run);
            $invocation->recordDelivered($stream->usage ?? null);
            yield $this->event('article_complete', ['run' => $this->runs->present($run)]);
        } catch (Throwable $exception) {
            $code = $this->errorCode($exception);
            $run['status'] = $code === 'cancelled' ? 'cancelled' : 'generation_failed';
            $run['error_code'] = $code;
            $run['content'] = $this->cleaner->cleanContent($run['content']);
            $run['html'] = $this->html($run);
            if ($exception instanceof AiModelAccessException) {
                $invocation?->recordRevoked($exception->getErrorCode(), $stream?->usage ?? $receivedUsage);
            } elseif ($providerReturned || $code === 'cancelled') {
                $invocation?->recordDiscarded($code, $stream?->usage ?? $receivedUsage);
            } else {
                $invocation?->recordProviderFailure($code);
            }
            $this->runs->save($run);
            $this->logFailure($run, $code);
            yield $this->event('error', ['run' => $this->runs->present($run)]);
        } finally {
            try {
                $session?->abort();
                $invocation?->close();
            } finally {
                $lock->release();
            }
        }
    }

    /** @return array<string,mixed> */
    public function find(Admin $admin, int $promptId, string $runId): array
    {
        $run = $this->runs->find($admin->id, $promptId, $runId);
        abort_if($run === null, 404, __('prompt_test.expired'));
        $this->guard->assertCurrent($run['generation_context']);

        return $run;
    }

    /** @return array<string,mixed> */
    public function diagnose(Admin $admin, int $promptId, string $runId, string $requestId, ?int $modelId): array
    {
        $lock = $this->runs->acquire($admin->id, $this->diagnostic->timeout() + 90);
        abort_if($lock === null, 409, __('prompt_test.busy'));
        $invocation = null;
        $response = null;
        $externalRequestAttempted = false;
        try {
            $run = $this->find($admin, $promptId, $runId);
            if ($run['status'] === 'completed' && ($run['diagnosis']['unresolved_suggestions'] ?? 0) === 0) {
                return $this->runs->present($run);
            }
            abort_if(in_array($requestId, $run['diagnosis_attempt_ids'], true), 409, __('prompt_test.duplicate'));
            $effectiveStatus = $this->runs->present($run)['status'];
            abort_unless(in_array($effectiveStatus, ['article_ready', 'diagnosis_failed', 'completed'], true), 409, __('prompt_test.not_ready'));
            abort_if($this->runs->cancelled($run), 409, __('prompt_test.errors.cancelled'));
            abort_if($this->diagnostic->timeout() + 60 >= $run['expires_at'] - now()->timestamp, 410, __('prompt_test.expired'));
            $context = $this->guard->freeze($admin, 'prompt_test_diagnostic', $promptId,
                requestedModelId: $modelId ?? $run['diagnostic_model']['id']);
            $model = $this->guard->resolveModel($context)['model'];
            $run['diagnostic_context'] = $context;
            $run['diagnostic_model'] = ['id' => (int) $model->id, 'name' => (string) $model->name];
            $run['diagnosis_request_id'] = $requestId;
            $run['diagnosis_attempt_ids'][] = $requestId;
            $run['status'] = 'diagnosing';
            $run['error_code'] = null;
            $run['deadline'] = now()->timestamp + $this->diagnostic->timeout() + 30;
            $this->runs->save($run);
            try {
                $input = $this->diagnostic->input($run);
                $invocation = $this->gateway->acquire($context, $this->diagnostic->timeout() + 60);
                $this->assertRunning($run, true);
                $response = $this->diagnostic->review($invocation->model, $input, function () use ($invocation, $input, $promptId, &$externalRequestAttempted): void {
                    $invocation->beginUsageAttempt($input, 'prompt_test.diagnose', 'prompt_test', Prompt::class, $promptId);
                    $externalRequestAttempted = true;
                });
                $this->assertRunning($run, true);
                $run['diagnosis'] = $this->diagnostic->validate($response, $run);
                $run['status'] = 'completed';
                $this->runs->save($run);
                $invocation->recordSuccess($response->usage);
            } catch (Throwable $exception) {
                $code = $this->errorCode($exception);
                if ($externalRequestAttempted) {
                    $this->usageQuota->recordModelAttempt($invocation->reservation);
                }
                $run['status'] = $code === 'cancelled' ? 'cancelled' : 'diagnosis_failed';
                $run['error_code'] = $code;
                if ($exception instanceof AiModelAccessException) {
                    $invocation?->recordRevoked($exception->getErrorCode(), $response?->usage);
                } elseif ($response !== null) {
                    $invocation?->recordDiscarded($code, $response->usage);
                } else {
                    $invocation?->recordProviderFailure($code);
                }
                $this->runs->save($run);
                $this->logFailure($run, $code);
            }

            return $this->runs->present($run);
        } finally {
            try {
                $invocation?->close();
            } finally {
                $lock->release();
            }
        }
    }

    /** @param array<string,mixed> $run */
    private function assertRunning(array $run, bool $diagnosis = false): void
    {
        if ($this->runs->cancelled($run) || connection_aborted()) {
            throw new RuntimeException('cancelled');
        }
        if (now()->timestamp > $run['deadline']) {
            throw new RuntimeException('interrupted');
        }
        $context = $run[$diagnosis ? 'diagnostic_context' : 'generation_context'];
        $this->guard->assertModelCurrent($context, $context->requestedModelId);
    }

    /** @param array<string,mixed> $run */
    private function html(array $run): string
    {
        return ArticleHtmlPresenter::markdownToHtml(ArticleHtmlPresenter::stripLeadingTitleHeading(
            $this->cleaner->cleanContent($run['content']), $run['title'],
        ));
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof AiModelAccessException) {
            return 'access_changed';
        }
        if ($exception instanceof InvalidArgumentException) {
            return 'invalid_diagnosis';
        }

        return in_array($exception->getMessage(), ['cancelled', 'knowledge_unavailable', 'incomplete', 'content_too_long', 'interrupted'], true)
            ? $exception->getMessage() : 'model_failed';
    }

    /** @param array<string,mixed> $payload */
    private function event(string $type, array $payload): string
    {
        return 'data: '.json_encode(['type' => $type, ...$payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
    }

    /** @param array<string,mixed> $run */
    private function logFailure(array $run, string $code): void
    {
        Log::notice('Prompt test stage ended.', ['run_id' => $run['id'], 'status' => $run['status'], 'error_code' => $code]);
    }
}
