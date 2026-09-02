<?php

namespace App\Jobs;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Services\GeoFlow\EnterpriseKnowledgeAiExecutionGuard;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GenerateEnterpriseKnowledgeDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * The serialized claim lease lets Laravel's reconstructed failed callback
     * prove that it still owns the execution it is trying to fail.
     */
    public ?string $claimLeaseToken = null;

    private ?string $claimedExecutionLeaseToken = null;

    public function __construct(public readonly int $projectId, ?string $claimLeaseToken = null)
    {
        $this->claimLeaseToken = is_string($claimLeaseToken) && Str::isUuid($claimLeaseToken)
            ? $claimLeaseToken
            : (string) Str::uuid();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['enterprise-knowledge', 'enterprise-knowledge:'.$this->projectId];
    }

    public function handle(
        EnterpriseKnowledgeDraftService $draftService,
        ?EnterpriseKnowledgeAiExecutionGuard $executionGuard = null,
        ?AiExecutionErrorSanitizer $errorSanitizer = null,
    ): void {
        $executionGuard ??= app(EnterpriseKnowledgeAiExecutionGuard::class);
        $errorSanitizer ??= app(AiExecutionErrorSanitizer::class);
        $project = EnterpriseKnowledgeProject::query()
            ->with('sources')
            ->whereKey($this->projectId)
            ->first();
        if (! $project instanceof EnterpriseKnowledgeProject) {
            return;
        }

        $claimLeaseToken = $this->originalClaimLeaseToken() ?? (string) Str::uuid();
        $claim = $executionGuard->claim($project, $claimLeaseToken);
        $project = $claim['project'];
        if (! $claim['claimed']) {
            return;
        }
        $this->claimedExecutionLeaseToken = trim((string) $project->execution_lease_token);

        try {
            $executionGuard->assertCurrent($project, $project->requested_ai_model_id);
            $this->updateProgress($project, 'collecting', 20, __('admin.enterprise_knowledge.progress_message.collecting'));
            $this->updateProgress($project, 'cleaning', 35, __('admin.enterprise_knowledge.progress_message.cleaning'));
            $this->updateProgress($project, 'structuring', 58, __('admin.enterprise_knowledge.progress_message.structuring'));

            $freshProject = $project->fresh(['sources']) ?? $project;
            $draft = $draftService->generateDraft($freshProject);
            $project->refresh();
            $content = trim((string) $draft['content']);

            $this->updateProgress($project, 'validating', 78, __('admin.enterprise_knowledge.progress_message.validating'));
            $validationItems = $draftService->validateDraft($content);
            $this->updateProgress($project, 'writing', 92, __('admin.enterprise_knowledge.progress_message.writing'));
            $this->persistDraft($project, $content, $validationItems, $draft, $executionGuard);
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $this->markFailed(
                $project,
                $exception,
                false,
                $errorSanitizer,
                $this->claimedExecutionLeaseToken,
            );
        } catch (Throwable $exception) {
            $this->markFailed(
                $project,
                $exception,
                true,
                $errorSanitizer,
                $this->claimedExecutionLeaseToken,
            );
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $claimLeaseToken = $this->originalClaimLeaseToken();
        if ($claimLeaseToken === null) {
            return;
        }

        $project = EnterpriseKnowledgeProject::query()->whereKey($this->projectId)->first();
        if ($project instanceof EnterpriseKnowledgeProject && $exception instanceof Throwable) {
            $this->markFailed(
                $project,
                $exception,
                true,
                app(AiExecutionErrorSanitizer::class),
                $claimLeaseToken,
            );
        }
    }

    private function updateProgress(
        EnterpriseKnowledgeProject $project,
        string $step,
        int $progress,
        string $message,
    ): void {
        $lease = trim((string) ($project->execution_lease_token ?? ''));
        if ($lease === '') {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($project->model_access_admin_id ?? 0),
            );
        }

        $attributes = [
            'status' => 'processing',
            'structured_json' => $this->progressJson($project, $step, $progress, $message),
            'lease_expires_at' => now()->addSeconds(EnterpriseKnowledgeAiExecutionGuard::EXECUTION_LEASE_SECONDS),
        ];
        $affected = EnterpriseKnowledgeProject::query()
            ->whereKey($project->getKey())
            ->where('status', 'processing')
            ->where('execution_lease_token', $lease)
            ->where('lease_expires_at', '>', now())
            ->update($attributes);
        if ($affected !== 1) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($project->model_access_admin_id ?? 0),
            );
        }

        $project->forceFill($attributes);
    }

    /**
     * @param  list<array{level:string,message:string,section:string}>  $validationItems
     * @param  array{content:string,source:string,model_id:?int,error:?string}  $draft
     */
    private function persistDraft(
        EnterpriseKnowledgeProject $project,
        string $content,
        array $validationItems,
        array $draft,
        EnterpriseKnowledgeAiExecutionGuard $executionGuard,
    ): void {
        DB::transaction(function () use ($project, $content, $validationItems, $draft, $executionGuard): void {
            $lockedProject = EnterpriseKnowledgeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $resolvedModelId = (int) ($lockedProject->resolved_ai_model_id ?? 0);
            $executionGuard->assertCurrent(
                $project,
                $resolvedModelId > 0 ? $resolvedModelId : $lockedProject->requested_ai_model_id,
            );

            $lockedProject->forceFill([
                'status' => 'reviewing',
                'draft_content' => $content,
                'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
                'ai_model_id' => $draft['model_id'],
                'error_code' => null,
                'error_message' => $draft['error'],
                'retryable_failure' => true,
                'structured_json' => $this->progressJson($lockedProject, 'completed', 100, __('admin.enterprise_knowledge.progress_message.completed')),
                'execution_lease_token' => null,
                'lease_expires_at' => null,
            ])->save();

            EnterpriseKnowledgeRevision::query()->create([
                'enterprise_knowledge_project_id' => (int) $lockedProject->id,
                'content' => $content,
                'summary' => $draft['source'] === 'ai'
                    ? __('admin.enterprise_knowledge.revision_ai')
                    : __('admin.enterprise_knowledge.revision_fallback'),
                'source' => (string) $draft['source'],
                'created_by_admin_id' => $lockedProject->model_access_admin_id,
                'content_hash' => hash('sha256', $content),
            ]);
        }, 3);

        $project->refresh();
    }

    private function markFailed(
        EnterpriseKnowledgeProject $project,
        Throwable $exception,
        bool $retryable,
        AiExecutionErrorSanitizer $errorSanitizer,
        ?string $claimLeaseToken,
    ): void {
        $fallback = $exception instanceof AiModelAccessException
            ? $exception->getErrorCode()
            : 'enterprise_knowledge_generation_failed';
        $message = $errorSanitizer->sanitize($exception, $fallback);
        $errorCode = match (true) {
            $exception instanceof AiModelAccessException => $exception->getErrorCode(),
            $exception instanceof PermanentAiProviderException => $exception->getErrorCode(),
            default => $retryable ? null : $fallback,
        };
        $claimLeaseToken = trim((string) $claimLeaseToken);
        if (! Str::isUuid($claimLeaseToken)) {
            return;
        }

        EnterpriseKnowledgeProject::query()
            ->whereKey($project->getKey())
            ->where('status', 'processing')
            ->where('execution_lease_token', $claimLeaseToken)
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $message,
                'retryable_failure' => $retryable,
                'structured_json' => $this->progressJson($project, 'failed', 100, __('admin.enterprise_knowledge.progress_message.failed', ['message' => $message])),
                'execution_lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function originalClaimLeaseToken(): ?string
    {
        $claimLeaseToken = trim((string) ($this->claimLeaseToken ?? ''));

        return Str::isUuid($claimLeaseToken) ? $claimLeaseToken : null;
    }

    private function progressJson(
        EnterpriseKnowledgeProject $project,
        string $step,
        int $progress,
        string $message,
    ): string {
        $data = $project->structuredData();
        $previous = is_array($data['draft_generation'] ?? null) ? $data['draft_generation'] : [];
        $startedAt = (string) ($previous['started_at'] ?? now()->toIso8601String());

        $data['draft_generation'] = [
            'step' => $step,
            'progress' => max(0, min(100, $progress)),
            'message' => $message,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
