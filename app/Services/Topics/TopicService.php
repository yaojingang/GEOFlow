<?php

namespace App\Services\Topics;

use App\Models\Article;
use App\Models\HostedSiteProfile;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicPath;
use App\Models\TopicRevision;
use App\Models\TopicSourceInvalidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TopicService
{
    public function __construct(
        private readonly TopicPayload $payloads,
        private readonly TopicViewBuilder $views,
        private readonly TopicSiteSettings $settings,
        private readonly TopicFreshnessService $freshness,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(string $siteKey, array $payload, ?int $adminId = null): Topic
    {
        $this->assertValidSite($siteKey);
        $draft = $this->payloads->normalize(app(TopicEvidenceService::class)->resolveQuotes($siteKey, $payload));
        $draft['_content_modified_at'] = now()->toIso8601String();
        app(TopicTemplateCatalog::class)->assertAvailableForSite($siteKey, $draft['template_key']);
        $metadata = Validator::make($payload, [
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'not_in:page'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'display_order' => ['sometimes', 'integer', 'between:-1000000,1000000'],
        ])->validate();

        return $this->withUniqueErrors(fn (): Topic => DB::transaction(function () use ($siteKey, $draft, $metadata, $adminId, $payload): Topic {
            $this->assertTitleAvailable($siteKey, $draft);
            $slug = $metadata['slug'] ?? 'topic-'.Str::lower((string) Str::ulid());
            if (Topic::withTrashed()->where('site_key', $siteKey)->where('slug', $slug)->exists() || TopicPath::query()->where('site_key', $siteKey)->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => '此专题路径已被使用，回收站中的路径也会保留。']);
            }

            $created = Topic::query()->create([
                'site_key' => $siteKey, 'slug' => $slug, 'title' => $draft['title'],
                'normalized_title_key' => TopicPayload::topicKey($draft['title'], $draft),
                'display_order' => $metadata['display_order'] ?? 0,
                'owner_admin_id' => $adminId, 'task_id' => $metadata['task_id'] ?? null,
                'draft_payload' => $draft, 'draft_version' => 1, 'draft_content_composed_at' => now(),
                'manual_edit_version' => $adminId !== null && ! ($payload['automatic_write'] ?? false) && (trim($draft['intro']) !== '' || ! empty($draft['articles']) || ! empty($draft['tags']) || ! empty($draft['summary']['one_sentence']) || ! empty($draft['summary']['facts'])) ? 1 : 0,
                'draft_source_hashes' => $hashes = $this->sourceBindings($siteKey, $draft, $payload),
                'draft_score_binding' => $this->scoreBinding($draft, $hashes),
            ]);
            app(TopicPathService::class)->register($created);
            $this->recordCompletion($created);

            return $created;
        }, 3));
    }

    /** @param array<string,mixed> $payload */
    public function save(Topic $topic, array $payload, int $expectedVersion): Topic
    {
        return $this->withUniqueErrors(fn (): Topic => DB::transaction(function () use ($topic, $payload, $expectedVersion): Topic {
            $locked = $this->lockTopic($topic);
            $this->assertVersion($locked, $expectedVersion);
            if (isset($payload['slug']) && $payload['slug'] !== $locked->slug) {
                throw ValidationException::withMessages(['slug' => '专题路径在创建后保持固定。']);
            }
            $draft = $this->payloads->normalize(app(TopicEvidenceService::class)->resolveQuotes($locked->site_key, array_replace($locked->draft_payload, $payload)));
            $this->assertTitleAvailable($locked->site_key, $draft, (int) $locked->id);
            app(TopicTemplateCatalog::class)->assertAvailableForSite($locked->site_key, $draft['template_key']);
            $metadata = Validator::make($payload, ['display_order' => ['sometimes', 'integer', 'between:-1000000,1000000']])->validate();
            $hashes = $this->sourceBindings($locked->site_key, $draft, $payload, $locked);
            $scoreBinding = $this->scoreBinding($draft, $hashes, $locked);
            $contentKeys = array_flip(['title', 'intro', 'summary', 'tags', 'articles', 'basic_info', 'faq']);
            $composedAt = array_intersect_key($draft, $contentKeys) !== array_intersect_key($locked->draft_payload, $contentKeys) ? now() : $locked->draft_content_composed_at;
            $publicContentKeys = $contentKeys + array_flip(['seo', 'freshness', 'score']);
            $draft['_content_modified_at'] = array_intersect_key($draft, $publicContentKeys) !== array_intersect_key($locked->draft_payload, $publicContentKeys)
                ? now()->toIso8601String()
                : ($locked->draft_payload['_content_modified_at'] ?? ($locked->draft_content_composed_at ?? $locked->created_at)->toIso8601String());
            $locked->update([
                'display_order' => $metadata['display_order'] ?? $locked->display_order,
                'draft_content_composed_at' => $composedAt,
                'manual_edit_version' => $locked->manual_edit_version + (($payload['automatic_write'] ?? false) ? 0 : 1),
                'draft_source_hashes' => $hashes, 'draft_score_binding' => $scoreBinding,
                'title' => $draft['title'], 'normalized_title_key' => TopicPayload::topicKey($draft['title'], $draft),
                'draft_payload' => $draft, 'draft_version' => $locked->draft_version + 1,
            ]);

            $this->recordCompletion($locked);

            return $locked->refresh();
        }, 3));
    }

    private function recordCompletion(Topic $topic): void
    {
        if (! $topic->task_id || $topic->result_completed_at !== null) {
            return;
        }
        $view = $this->views->build($topic, $topic->draft_payload, null, true);
        if ($view['article_count'] < 2 || trim($view['intro']) === '') {
            return;
        }
        try {
            $this->payloads->assertPublishable($topic->draft_payload, array_column($view['articles'], 'article_id'));
        } catch (ValidationException) {
            return;
        }
        $topic->update(['result_completed_at' => now()]);
    }

    public function publish(Topic $topic, int $expectedVersion, ?int $actorId = null, bool $reviewRequired = false, ?string $requestId = null): TopicRevision
    {
        Validator::make(['request_id' => $requestId], ['request_id' => ['nullable', 'string', 'min:1', 'max:100']])->validate();

        return DB::transaction(function () use ($topic, $expectedVersion, $actorId, $reviewRequired, $requestId): TopicRevision {
            $locked = $this->lockTopic($topic);
            $this->assertPublishingEnabled($locked->site_key);
            if ($requestId !== null) {
                $requestRevisionId = $locked->publication_requests[$requestId] ?? null;
                $previous = $requestRevisionId === null ? null : $locked->revisions()->whereKey($requestRevisionId)->first();
                if ($previous !== null) {
                    if ($previous->draft_version !== $expectedVersion) {
                        throw ValidationException::withMessages(['request_id' => '此发布请求编号已用于其他草稿版本。']);
                    }

                    return $previous;
                }
            }
            $this->assertVersion($locked, $expectedVersion);
            $articles = $this->eligibleArticles($locked->site_key, $locked->draft_payload);
            $this->payloads->assertPublishable($locked->draft_payload, $articles->modelKeys());
            $this->assertSourcesCurrent($locked, $articles, $locked->draft_source_hashes ?? []);
            app(TopicEvidenceService::class)->assertCurrent($locked->draft_payload, $articles);
            $this->freshness->assertConfiguration($locked->draft_payload['freshness']);
            $snapshot = $this->freshness->snapshot($locked->draft_payload['freshness'], $articles, $locked->draft_content_composed_at ? CarbonImmutable::instance($locked->draft_content_composed_at) : null);
            $this->freshness->assertPublishable($snapshot, ! ($locked->task_id !== null && $reviewRequired && str_starts_with($requestId ?? '', 'build:')));
            $revision = $locked->revisions()->where('draft_version', $expectedVersion)->first();
            if ($revision === null) {
                $revision = $locked->revisions()->create([
                    'number' => ((int) $locked->revisions()->max('number')) + 1,
                    'path_generation' => $locked->path_generation, 'draft_version' => $expectedVersion, 'payload' => array_replace($locked->draft_payload, [
                        'articles' => array_values(array_filter($locked->draft_payload['articles'], fn (array $entry): bool => $articles->contains('id', $entry['article_id']))),
                        '_score_binding' => $locked->draft_score_binding, 'freshness_snapshot_json' => $snapshot,
                        '_content_modified_at' => $locked->draft_payload['_content_modified_at'] ?? ($locked->draft_content_composed_at ?? now())->toIso8601String(),
                    ]),
                    'freshness_snapshot_json' => $snapshot,
                    'request_id' => $requestId, 'created_by_admin_id' => $actorId,
                ]);
                foreach ($locked->draft_payload['articles'] as $order => $entry) {
                    $article = $articles->find($entry['article_id']);
                    if ($article === null) {
                        continue;
                    }
                    $revision->articles()->create([
                        'article_id' => $article->id, 'sort_order' => $order,
                        'group' => $entry['group'], 'reason' => $entry['reason'],
                        'content_hash' => self::contentHash($article),
                        'snapshot' => ['title' => $article->title, 'excerpt' => $article->excerpt, 'published_at' => $article->published_at?->toIso8601String()],
                    ]);
                }
            }
            $reviewRequired = $reviewRequired || (bool) $this->settings->get($locked->site_key)['require_review'];
            if ($reviewRequired) {
                if ($locked->pending_revision_id !== $revision->id && $locked->public_revision_id !== $revision->id) {
                    $locked->update(['pending_revision_id' => $revision->id, 'approved_revision_id' => null, 'approved_at' => null, 'approved_by_admin_id' => null, 'submitted_at' => now()]);
                }
            } else {
                $this->activate($locked, $revision, $actorId);
            }

            if ($requestId !== null) {
                $locked->update(['publication_requests' => array_replace($locked->publication_requests ?? [], [$requestId => $revision->id])]);
            }

            return $revision;
        }, 3);
    }

    public function approve(Topic $topic, ?int $actorId = null, ?int $expectedRevisionId = null): TopicRevision
    {
        return DB::transaction(function () use ($topic, $actorId, $expectedRevisionId): TopicRevision {
            $locked = $this->lockTopic($topic);
            $this->assertPublishingEnabled($locked->site_key);
            $revisionId = $locked->pending_revision_id ?? $locked->public_revision_id;
            if ($revisionId === null || ($expectedRevisionId !== null && $expectedRevisionId !== $revisionId)) {
                throw ValidationException::withMessages(['pending_revision_id' => '待审核版本已改变，请重新打开审核页面。']);
            }
            $revision = $locked->revisions()->whereKey($revisionId)->firstOrFail();
            $articles = $this->eligibleArticles($locked->site_key, $revision->payload);
            $this->payloads->assertPublishable($revision->payload, $articles->modelKeys());
            $revision->loadMissing('articles');
            $this->freshness->assertPublishable($revision->freshness_snapshot_json ?? $revision->payload['freshness_snapshot_json'] ?? [], false);
            $this->assertSourcesCurrent($locked, $articles, $revision->articles->pluck('content_hash', 'article_id')->all(), $revision);
            app(TopicEvidenceService::class)->assertCurrent($revision->payload, $articles);
            $reviewedAt = now();
            $locked->update([
                'review_records' => array_replace($locked->review_records ?? [], [$revision->id => [
                    'method' => 'editorial', 'reviewed_at' => $reviewedAt->toIso8601String(), 'actor_id' => $actorId,
                ]]),
                'approved_by_admin_id' => $actorId, 'approved_at' => $reviewedAt,
            ]);
            $run = TopicBuildRun::query()->where('topic_id', $locked->id)->whereNotNull('task_id')->where('expected_version', $revision->draft_version)->latest('id')->first();
            if ($run && ($run->input['defer_publication'] ?? false)) {
                $locked->update(['approved_revision_id' => $revision->id, 'approved_by_admin_id' => $actorId, 'approved_at' => $reviewedAt]);
            } else {
                $this->activate($locked, $revision, $actorId);
            }

            return $revision;
        }, 3);
    }

    public function publishApproved(Topic $topic, int $revisionId, ?int $actorId = null): TopicRevision
    {
        return DB::transaction(function () use ($topic, $revisionId, $actorId): TopicRevision {
            $locked = $this->lockTopic($topic);
            $this->assertPublishingEnabled($locked->site_key);
            if ($locked->approved_revision_id !== $revisionId || $locked->pending_revision_id !== $revisionId || $locked->withdrawn_at !== null) {
                throw ValidationException::withMessages(['pending_revision_id' => '审核版本已改变，请重新核对。']);
            }
            $revision = $locked->revisions()->with('articles')->findOrFail($revisionId);
            $articles = $this->eligibleArticles($locked->site_key, $revision->payload);
            $this->payloads->assertPublishable($revision->payload, $articles->modelKeys());
            $this->assertSourcesCurrent($locked, $articles, $revision->articles->pluck('content_hash', 'article_id')->all(), $revision);
            app(TopicEvidenceService::class)->assertCurrent($revision->payload, $articles);
            $this->activate($locked, $revision, $actorId);

            return $revision;
        }, 3);
    }

    public function withdraw(Topic $topic, ?int $actorId = null, ?int $expectedRevisionId = null, ?string $requestId = null): Topic
    {
        Validator::make(['request_id' => $requestId], ['request_id' => ['nullable', 'string', 'min:1', 'max:100']])->validate();

        return DB::transaction(function () use ($topic, $actorId, $expectedRevisionId, $requestId): Topic {
            $locked = $this->lockTopic($topic);
            $requests = $locked->withdrawal_requests ?? [];
            $requestHash = hash('sha256', json_encode(['expected_revision_id' => $expectedRevisionId, 'actor_id' => $actorId], JSON_THROW_ON_ERROR));
            if ($requestId !== null && array_key_exists($requestId, $requests)) {
                $previous = $requests[$requestId];
                $sameInput = is_array($previous)
                    ? hash_equals((string) ($previous['request_hash'] ?? ''), $requestHash)
                    : ($expectedRevisionId === null || $expectedRevisionId === $previous);
                if (! $sameInput) {
                    throw ValidationException::withMessages(['request_id' => '此撤回请求编号已用于其他输入，请刷新专题状态并使用新请求。'])->status(409);
                }

                return $locked;
            }
            $revisionId = $locked->public_revision_id ?? $locked->pending_revision_id;
            if ($expectedRevisionId !== null && $expectedRevisionId !== $revisionId) {
                throw ValidationException::withMessages(['public_revision_id' => '专题发布版本已改变，请刷新后再撤回。']);
            }
            if ($locked->public_revision_id !== null || $locked->pending_revision_id !== null || $locked->maintenance_paused_at === null) {
                $locked->update([
                    'maintenance_paused_at' => $locked->maintenance_paused_at ?? now(),
                    'withdrawn_at' => $locked->public_revision_id !== null ? now() : $locked->withdrawn_at,
                    'withdrawn_by_admin_id' => $locked->public_revision_id !== null ? $actorId : $locked->withdrawn_by_admin_id,
                    'public_revision_id' => null, 'pending_revision_id' => null, 'approved_revision_id' => null, 'approved_at' => null, 'approved_by_admin_id' => null, 'submitted_at' => null,
                ]);
            }

            if ($requestId !== null) {
                $locked->update(['withdrawal_requests' => array_replace($requests, [$requestId => ['revision_id' => $revisionId, 'request_hash' => $requestHash]])]);
            }

            return $locked->refresh();
        }, 3);
    }

    public function restoreRevision(Topic $topic, int $revisionId, int $expectedVersion): Topic
    {
        return $this->withUniqueErrors(fn (): Topic => DB::transaction(function () use ($topic, $revisionId, $expectedVersion): Topic {
            $locked = $this->lockTopic($topic);
            $this->assertVersion($locked, $expectedVersion);
            $revision = $locked->revisions()->with('articles')->whereKey($revisionId)->first();
            if ($revision === null) {
                throw ValidationException::withMessages(['revision_id' => '请选择此专题的历史版本。']);
            }
            $draft = $this->views->effectivePayload($locked, $revision->payload, $revision);
            $scoreBinding = $draft['_score_binding'] ?? null;
            unset($draft['_score_binding']);
            $draft['_content_modified_at'] = now()->toIso8601String();
            $hashes = $this->eligibleArticles($locked->site_key, $draft)->mapWithKeys(fn (Article $article): array => [$article->id => self::contentHash($article)])->all();
            $this->assertTitleAvailable($locked->site_key, $draft, (int) $locked->id);
            app(TopicTemplateCatalog::class)->assertAvailableForSite($locked->site_key, $draft['template_key']);

            $locked->update([
                'manual_edit_version' => $locked->manual_edit_version + 1,
                'draft_source_hashes' => $hashes, 'draft_score_binding' => $scoreBinding,
                'title' => $draft['title'], 'normalized_title_key' => TopicPayload::topicKey($draft['title'], $draft),
                'draft_payload' => $draft, 'draft_version' => $locked->draft_version + 1,
                'draft_content_composed_at' => now(),
            ]);

            return $locked->refresh();
        }, 3));
    }

    /** @return array<string,mixed>|null */
    public function publicView(Topic $topic): ?array
    {
        $current = $this->views->isReading($topic) ? $topic : Topic::query()->useWritePdo()->with('publicRevision.articles')->find($topic->id);
        if ($current === null || $current->publicRevision === null || $current->publicRevision->topic_id !== $current->id) {
            return null;
        }
        $view = $this->views->build($current, $current->publicRevision->payload, $current->publicRevision);

        return $view['article_count'] >= 2 && $view['public_allowed'] ? $view : null;
    }

    /** @return array<string,mixed> */
    public function previewView(Topic $topic): array
    {
        $current = $this->views->isReading($topic) ? $topic : Topic::query()->useWritePdo()->findOrFail($topic->id);

        return $this->views->build($current, $current->draft_payload, preview: true);
    }

    public static function contentHash(Article $article): string
    {
        return hash('sha256', json_encode([
            'title' => (string) $article->title, 'excerpt' => (string) $article->excerpt,
            'content' => (string) $article->content, 'keywords' => (string) $article->keywords,
            'meta_description' => (string) $article->meta_description,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $payload @return Collection<int,Article> */
    private function eligibleArticles(string $siteKey, array $payload, bool $lock = false): Collection
    {
        return $this->views->eligibleArticles($siteKey, $payload, $lock);
    }

    public function isEligible(Article $article, string $siteKey): bool
    {
        return $this->views->isEligible($article, $siteKey);
    }

    /** Caller must already have applied the public site scope. */
    public function isEligibleScoped(Article $article): bool
    {
        return $this->views->isEligibleScoped($article);
    }

    public static function bodyHash(Article $article): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", trim((string) $article->content)));
    }

    /** @param Collection<int,Article> $articles @param array<int|string,string> $hashes */
    private function assertSourcesCurrent(Topic $topic, Collection $articles, array $hashes, ?TopicRevision $revision = null): void
    {
        $basisKeys = $revision === null
            ? array_map(static fn (string $hash): string => 'draft:'.$topic->id.':'.$hash, array_values($hashes))
            : ['revision:'.$revision->id];
        if (TopicSourceInvalidation::query()->whereIn('basis_key', $basisKeys)->exists()) {
            throw ValidationException::withMessages(['articles' => '此版本的来源曾失效，请重新核对来源并保存新版本后发布。']);
        }
        foreach ($hashes as $articleId => $hash) {
            $article = $articles->find((int) $articleId);
            if ($article === null || ! hash_equals((string) $hash, self::contentHash($article))) {
                throw ValidationException::withMessages(['articles' => '来源内容或公开资格已改变，请重新核对导语和事实，再保存新版本。']);
            }
        }
        if (count($hashes) !== $articles->count()) {
            throw ValidationException::withMessages(['articles' => '文章来源缺少当前内容版本，请重新核对来源后保存。']);
        }
    }

    /** @param array<string,mixed> $draft @param array<string,mixed> $input @return array<int|string,string> */
    private function sourceBindings(string $siteKey, array $draft, array $input, ?Topic $previous = null): array
    {
        $current = $this->eligibleArticles($siteKey, $draft)->keyBy('id');
        $provided = array_key_exists('source_hashes', $input);
        if ($provided) {
            Validator::make($input, ['source_hashes' => ['required', 'array'], 'source_hashes.*' => ['string', 'regex:/^[a-f0-9]{64}$/D']])->validate();
        }
        $hashes = [];
        foreach ($draft['articles'] as $entry) {
            $id = $entry['article_id'];
            if ($provided) {
                $hash = $input['source_hashes'][$id] ?? '';
                if (! $current->has($id) || ! hash_equals($hash, self::contentHash($current->get($id)))) {
                    throw ValidationException::withMessages(['articles' => '重新核对的来源已改变或不可公开，请重新读取文章。']);
                }
            } else {
                $hash = $previous?->draft_source_hashes[$id] ?? ($current->has($id) ? self::contentHash($current->get($id)) : '');
            }
            $hashes[$id] = $hash;
        }
        if ($provided && $previous !== null) {
            TopicSourceInvalidation::query()->where('topic_id', $previous->id)->whereNull('topic_revision_id')
                ->whereIn('article_id', array_keys($hashes))->delete();
        }

        return $hashes;
    }

    /** @param array<string,mixed> $draft @param array<int|string,string> $hashes @return array<string,mixed>|null */
    private function scoreBinding(array $draft, array $hashes, ?Topic $previous = null): ?array
    {
        if ($draft['score'] === null) {
            return null;
        }
        $oldScore = $previous?->draft_payload['score'] ?? null;
        $newScore = $draft['score'];
        unset($newScore['enabled']);
        if (is_array($oldScore)) {
            unset($oldScore['enabled']);
        }
        if ($previous !== null && $newScore === $oldScore && $previous->draft_score_binding !== null) {
            return $previous->draft_score_binding;
        }
        if (! $this->payloads->validScoreData($draft['score'], array_map('intval', array_keys($hashes)), $draft['freshness']['timezone'])) {
            return null;
        }
        $ratedAt = isset($draft['score']['rated_at']) ? CarbonImmutable::parse($draft['score']['rated_at']) : CarbonImmutable::now();

        return [
            'baseline_hash' => TopicPayload::ratingHash($draft, $hashes),
            'rated_at' => $ratedAt->toIso8601String(),
            'valid_until' => $this->payloads->scoreExpiresAt($draft, $ratedAt)->subMicrosecond()->setTimezone($draft['freshness']['timezone'])->toDateString(),
            'expires_at' => $this->payloads->scoreExpiresAt($draft, $ratedAt)->utc()->toIso8601String(),
        ];
    }

    private function lockTopic(Topic $topic): Topic
    {
        return Topic::query()->whereKey($topic->id)->lockForUpdate()->firstOrFail();
    }

    private function assertVersion(Topic $topic, int $expectedVersion): void
    {
        if ($topic->draft_version !== $expectedVersion) {
            throw ValidationException::withMessages(['draft_version' => '专题草稿已被修改，请刷新后再保存或发布。']);
        }
    }

    private function activate(Topic $topic, TopicRevision $revision, ?int $actorId): void
    {
        if ($topic->public_revision_id === $revision->id) {
            return;
        }
        $this->assertPublishingEnabled($topic->site_key);
        if ($revision->path_generation !== $topic->path_generation) {
            throw ValidationException::withMessages(['path' => '专题地址已改变，请重新核对并提交当前草稿。']);
        }
        $snapshot = $revision->freshness_snapshot_json ?? $revision->payload['freshness_snapshot_json'] ?? [];
        $this->freshness->assertPublishable($snapshot);
        $review = $topic->review_records[$revision->id] ?? null;
        $topic->update([
            'public_revision_id' => $revision->id, 'approved_revision_id' => null,
            'approved_by_admin_id' => $review['actor_id'] ?? null, 'approved_at' => $review['reviewed_at'] ?? null,
            'pending_revision_id' => null, 'submitted_at' => null,
            'published_by_admin_id' => $actorId,
            'first_published_at' => $topic->first_published_at ?? now(), 'published_at' => now(), 'withdrawn_at' => null,
        ]);
        $this->settings->recordOpened($topic->site_key);
        $this->freshness->recordState($topic, $revision, $this->freshness->evaluate($snapshot));
    }

    public function assertPublishingEnabled(string $siteKey): void
    {
        app(TopicNamespaceGuard::class)->assertAvailable($siteKey);
        if (! $this->settings->get($siteKey)['enabled']) {
            throw ValidationException::withMessages(['site' => '此站点的专题频道已关闭，工作草稿已保留；开启频道后可以继续发布。']);
        }
    }

    public function assertValidSite(string $siteKey): void
    {
        if ($siteKey === 'primary') {
            return;
        }
        if (preg_match('/^hosted:([1-9][0-9]*)$/D', $siteKey, $matches) !== 1 || ! HostedSiteProfile::query()->whereKey((int) $matches[1])->exists()) {
            throw ValidationException::withMessages(['site_key' => '请选择主站或有效的托管站点。']);
        }
    }

    private function assertTitleAvailable(string $siteKey, array $draft, ?int $exceptId = null): void
    {
        $titleHash = TopicPayload::titleKey($draft['title']);
        $key = TopicPayload::topicKey($draft['title'], $draft);
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['topic:'.$siteKey.':'.$titleHash]);
        }
        $legacy = Topic::withTrashed()->where('site_key', $siteKey)->where('normalized_title_key', $titleHash)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->orderBy('id')->lockForUpdate()->get();
        foreach ($legacy as $existing) {
            if (TopicPayload::titleKey($existing->title) !== $titleHash) {
                continue;
            }
            $actualKey = TopicPayload::topicKey($existing->title, $existing->draft_payload);
            if ($actualKey === $key) {
                throw ValidationException::withMessages(['title' => '此站点已有相同标题和范围的专题 #'.$existing->id.'，回收站中的主题也会保留。请查看原专题或填写不同适用范围。']);
            }
            if ($actualKey !== $titleHash) {
                $collision = Topic::withTrashed()->where('site_key', $siteKey)->where('normalized_title_key', $actualKey)->whereKeyNot($existing->id)->first();
                if ($collision !== null) {
                    throw ValidationException::withMessages(['title' => '现有专题 #'.$existing->id.' 与 #'.$collision->id.' 的标题和范围重复，请先核对两份内容。']);
                }
                DB::table('topics')->where('id', $existing->id)->where('site_key', $siteKey)->where('normalized_title_key', $titleHash)->update(['normalized_title_key' => $actualKey]);
            }
        }
        $existing = Topic::withTrashed()->where('site_key', $siteKey)->where('normalized_title_key', $key)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['title' => '此站点已有相同标题和范围的专题 #'.$existing->id.'，回收站中的主题也会保留。请查看原专题或填写不同适用范围。']);
        }
    }

    /** @param callable():Topic $operation */
    private function withUniqueErrors(callable $operation): Topic
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'topics_site_title_unique') || str_contains($message, 'topics.normalized_title_key')) {
                throw ValidationException::withMessages(['title' => '此站点已有相同标题和范围的专题，回收站中的主题也会保留。请查看原专题或填写不同适用范围。']);
            }
            if (str_contains($message, 'topics_site_key_slug_unique') || str_contains($message, 'topics.slug')) {
                throw ValidationException::withMessages(['slug' => '此专题路径已被使用。']);
            }

            throw $exception;
        }
    }
}
