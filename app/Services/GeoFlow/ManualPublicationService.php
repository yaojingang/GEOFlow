<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ManualPublicationConflictException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\ManualPublicationTransition;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ManualPublicationService
{
    public function __construct(
        private readonly ArticleRiskScanner $riskScanner,
        private readonly ManualPublicationDuplicateDetector $duplicateDetector,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, Admin $creator): ManualPublication
    {
        return DB::transaction(function () use ($data, $creator): ManualPublication {
            $prepared = $this->prepare($data);
            $prepared['created_by_admin_id'] = $creator->getKey();
            $initialStatus = (string) ($data['status'] ?? ManualPublication::STATUS_DRAFT);
            if (! in_array($initialStatus, [ManualPublication::STATUS_DRAFT, ManualPublication::STATUS_READY], true)) {
                throw new DomainException((string) __('admin.manual_publications.error.invalid_transition'));
            }
            $prepared['status'] = $initialStatus;
            $prepared['status_changed_at'] = now();
            $prepared['revision'] = 1;
            $this->ensureReadyRequirements($prepared);
            $prepared['duplicate_warning_count'] = $this->duplicateDetector->find($prepared)->count();

            $publication = ManualPublication::query()->create($prepared);
            $this->recordTransition($publication, null, $initialStatus, $creator);

            return $publication->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ManualPublication $manualPublication, array $data, int $expectedRevision): ManualPublication
    {
        if ($manualPublication->status === ManualPublication::STATUS_COMPLETED) {
            throw new DomainException((string) __('admin.manual_publications.error.completed_immutable'));
        }

        $prepared = $this->prepare($data, $manualPublication);
        $this->ensureReadyRequirements($prepared + ['status' => (string) $manualPublication->status]);
        $prepared['duplicate_warning_count'] = $this->duplicateDetector
            ->find($prepared, (int) $manualPublication->getKey())
            ->count();
        $prepared['updated_at'] = now();
        $prepared['revision'] = $expectedRevision + 1;
        $casted = (new ManualPublication)->forceFill($prepared);
        $databaseUpdates = Arr::only($casted->getAttributes(), array_keys($prepared));

        $updated = ManualPublication::query()
            ->whereKey($manualPublication->getKey())
            ->where('revision', $expectedRevision)
            ->where('status', '!=', ManualPublication::STATUS_COMPLETED)
            ->update($databaseUpdates);

        if ($updated !== 1) {
            throw new ManualPublicationConflictException;
        }

        return $manualPublication->refresh();
    }

    public function transition(
        ManualPublication $manualPublication,
        string $targetStatus,
        int $expectedRevision,
        Admin $actor,
        ?string $completionUrl = null,
        ?string $resultNote = null,
    ): ManualPublication {
        return DB::transaction(function () use ($manualPublication, $targetStatus, $expectedRevision, $completionUrl, $resultNote, $actor): ManualPublication {
            $current = ManualPublication::query()
                ->whereKey($manualPublication->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $current->revision !== $expectedRevision) {
                throw new ManualPublicationConflictException;
            }

            $ability = $current->isReopenTransition($targetStatus) ? 'reopen' : 'transition';
            Gate::forUser($actor)->authorize($ability, $current);

            if (! $current->canTransitionTo($targetStatus)) {
                throw new DomainException((string) __('admin.manual_publications.error.invalid_transition'));
            }

            $fromStatus = (string) $current->status;
            $transitionedAt = now();
            $normalizedResultNote = trim((string) $resultNote) ?: null;
            $updates = [
                'status' => $targetStatus,
                'status_changed_at' => $transitionedAt,
                'revision' => $expectedRevision + 1,
                'result_note' => $normalizedResultNote,
            ];

            if ($targetStatus === ManualPublication::STATUS_READY) {
                $this->ensureReadyRequirements(array_merge($current->getAttributes(), ['status' => $targetStatus]));
                $updates['completion_url'] = null;
                $updates['completed_at'] = null;
            }

            if ($targetStatus === ManualPublication::STATUS_COMPLETED) {
                $completionUrl = trim((string) $completionUrl);
                if (! $this->isHttpUrl($completionUrl)) {
                    throw new DomainException((string) __('admin.manual_publications.error.completion_url_required'));
                }
                $updates['completion_url'] = $completionUrl;
                $updates['completed_at'] = now();
            }

            $current->forceFill($updates)->save();
            $this->recordTransition(
                $current,
                $fromStatus,
                $targetStatus,
                $actor,
                $targetStatus === ManualPublication::STATUS_COMPLETED ? $completionUrl : null,
                $normalizedResultNote,
                $transitionedAt,
            );

            return $current->refresh();
        });
    }

    /** @return Collection<int, ManualPublication> */
    public function duplicatesFor(ManualPublication $manualPublication): Collection
    {
        return $this->duplicateDetector->find([
            'platform' => (string) $manualPublication->platform,
            'article_id' => $manualPublication->article_id === null ? null : (int) $manualPublication->article_id,
            'target_url_hash' => $manualPublication->target_url_hash,
            'content' => (string) $manualPublication->content,
            'content_fingerprint' => (string) $manualPublication->content_fingerprint,
        ], (int) $manualPublication->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?ManualPublication $existing = null): array
    {
        $type = (string) $data['type'];
        if (! in_array($type, ManualPublication::TYPES, true)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_type'));
        }
        $platform = (string) $data['platform'];
        if (! in_array($platform, ManualPublicationAccount::PLATFORMS, true)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_platform'));
        }
        $customPlatform = trim((string) ($data['custom_platform'] ?? '')) ?: null;
        if ($platform === ManualPublicationAccount::PLATFORM_CUSTOM && $customPlatform === null) {
            throw new DomainException((string) __('admin.manual_publications.error.custom_platform_required'));
        }

        $persona = ManualPublicationPersona::query()
            ->whereKey((int) $data['persona_id'])
            ->where('is_active', true)
            ->first();
        if (! $persona instanceof ManualPublicationPersona) {
            throw new DomainException((string) __('admin.manual_publications.error.persona_inactive'));
        }

        $account = null;
        if (! empty($data['account_id'])) {
            $account = ManualPublicationAccount::query()
                ->whereKey((int) $data['account_id'])
                ->where('is_active', true)
                ->first();
            if (! $account instanceof ManualPublicationAccount
                || (int) $account->persona_id !== (int) $persona->getKey()
                || $account->platform !== $platform
                || ($platform === ManualPublicationAccount::PLATFORM_CUSTOM
                    && $account->custom_platform !== null
                    && trim((string) $account->custom_platform) !== $customPlatform)) {
                throw new DomainException((string) __('admin.manual_publications.error.account_mismatch'));
            }
        }

        $article = null;
        if ($type === ManualPublication::TYPE_POST) {
            $article = Article::query()->find((int) $data['article_id']);
            if (! $article instanceof Article || ! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
                throw new DomainException((string) __('admin.manual_publications.error.article_not_approved'));
            }
        }

        if (! empty($data['assigned_admin_id'])) {
            $assigneeExists = Admin::query()
                ->whereKey((int) $data['assigned_admin_id'])
                ->where('status', 'active')
                ->exists();
            if (! $assigneeExists) {
                throw new DomainException((string) __('admin.manual_publications.error.assignee_inactive'));
            }
        }

        $content = trim((string) $data['content']);
        if ($content === '' || mb_strlen($content) > ManualPublication::MAX_CONTENT_CHARACTERS) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_content'));
        }
        $targetUrl = trim((string) ($data['target_url'] ?? '')) ?: null;
        $targetContext = trim((string) ($data['target_context'] ?? '')) ?: null;
        if ($targetUrl !== null && ! $this->isHttpUrl($targetUrl)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_target_url'));
        }
        if ($type === ManualPublication::TYPE_COMMENT && ($targetUrl === null || $targetContext === null)) {
            throw new DomainException((string) __('admin.manual_publications.error.comment_target_required'));
        }
        $targetUrlHash = $this->duplicateDetector->targetUrlHash($targetUrl);
        $contentFingerprint = $this->duplicateDetector->fingerprint($content);
        $riskResult = $this->riskScanner->scan([
            'title' => (string) ($article?->title ?? ''),
            'excerpt' => $targetContext ?? '',
            'content' => $content,
            'keywords' => '',
            'meta_description' => (string) ($persona->disclosure_text ?? ''),
        ]);

        $sourceSnapshot = $existing?->source_snapshot;
        if ($article instanceof Article && ((int) $existing?->article_id !== (int) $article->getKey() || ! is_array($sourceSnapshot))) {
            $sourceSnapshot = [
                'article_id' => (int) $article->getKey(),
                'title' => (string) $article->title,
                'slug' => (string) $article->slug,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'content' => (string) $article->content,
                'review_status' => (string) $article->review_status,
                'snapshotted_at' => now()->toAtomString(),
            ];
        }

        return [
            'type' => $type,
            'article_id' => $article?->getKey(),
            'persona_id' => $persona->getKey(),
            'account_id' => $account?->getKey(),
            'assigned_admin_id' => empty($data['assigned_admin_id']) ? null : (int) $data['assigned_admin_id'],
            'platform' => $platform,
            'custom_platform' => $customPlatform,
            'target_url' => $targetUrl,
            'target_url_hash' => $targetUrlHash,
            'target_context' => $targetContext,
            'content' => $content,
            'content_fingerprint' => $contentFingerprint,
            'source_snapshot' => $type === ManualPublication::TYPE_POST ? $sourceSnapshot : null,
            'identity_snapshot' => $this->identitySnapshot($persona, $account),
            'disclosure_snapshot' => trim((string) ($persona->disclosure_text ?? '')) ?: null,
            'risk_status' => (string) Arr::get($riskResult, 'status', 'clean'),
            'risk_result' => $riskResult,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function ensureReadyRequirements(array $attributes): void
    {
        if (($attributes['status'] ?? null) !== ManualPublication::STATUS_READY) {
            return;
        }
        if (empty($attributes['assigned_admin_id'])) {
            throw new DomainException((string) __('admin.manual_publications.error.ready_requires_assignee'));
        }
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** @return array<string, mixed> */
    private function identitySnapshot(ManualPublicationPersona $persona, ?ManualPublicationAccount $account): array
    {
        return [
            'persona' => [
                'id' => (int) $persona->getKey(),
                'name' => (string) $persona->name,
                'tone' => $persona->tone,
                'domain' => $persona->domain,
            ],
            'account' => $account instanceof ManualPublicationAccount ? [
                'id' => (int) $account->getKey(),
                'account_name' => (string) $account->account_name,
                'platform' => (string) $account->platform,
                'custom_platform' => $account->custom_platform,
                'profile_url' => $account->profile_url,
            ] : null,
            'snapshotted_at' => now()->toAtomString(),
        ];
    }

    private function recordTransition(
        ManualPublication $publication,
        ?string $fromStatus,
        string $toStatus,
        ?Admin $actor = null,
        ?string $completionUrl = null,
        ?string $resultNote = null,
        ?Carbon $createdAt = null,
    ): void {
        ManualPublicationTransition::query()->create([
            'manual_publication_id' => $publication->getKey(),
            'changed_by_admin_id' => $actor?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'completion_url' => $completionUrl,
            'result_note' => $resultNote,
            'created_at' => $createdAt ?? now(),
        ]);
    }
}
