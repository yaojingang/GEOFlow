<?php

namespace App\Services\Topics;

use App\Models\Article;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Topic;
use App\Models\TopicRevision;
use App\Models\TopicRevisionArticle;
use App\Models\TopicSourceInvalidation;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SiteScopedArticleQuery;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\ArticlePermalinkPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Validation\ValidationException;

final class TopicViewBuilder
{
    private ?ArticlePermalinkPolicy $readPolicy = null;

    private ?HostedSiteProfile $readProfile = null;

    public function isReading(Topic $topic): bool
    {
        return $this->readArticles !== null && $this->readSiteKey === $topic->site_key;
    }

    private ?Collection $readArticles = null;

    private array $readInvalidations = [];

    private array $readInvalidatedAt = [];

    private ?string $readSiteKey = null;

    public function withReadBatch(string $siteKey, Enumerable $topics, callable $read): mixed
    {
        $ids = $topics->flatMap(fn ($t) => collect(array_column($t->draft_payload['articles'] ?? [], 'article_id'))->merge($t->publicRevision?->articles->pluck('article_id') ?? collect()))->unique()->all();
        $previous = [$this->readArticles, $this->readInvalidations, $this->readSiteKey, $this->readPolicy, $this->readProfile, $this->readInvalidatedAt];
        $this->readPolicy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'));
        $this->readProfile = $siteKey === 'primary' ? null : HostedSiteProfile::query()->with('channel')->find((int) substr($siteKey, 7));
        $this->readArticles = $this->siteArticles->queryForSiteKey($siteKey)->useWritePdo()->whereIn('articles.id', $ids)->with(['category', 'task' => fn ($q) => $q->useWritePdo(), 'latestRiskScan' => fn ($q) => $q->useWritePdo(), 'latestTopicReview' => fn ($q) => $q->useWritePdo()])->get()->filter(fn ($a) => $this->hasEligibleApproval($a));
        $bases = $topics->flatMap(fn ($t) => ['revision:'.$t->public_revision_id, ...array_map(fn ($hash) => 'draft:'.$t->id.':'.$hash, array_values($t->draft_source_hashes ?? []))])->unique()->all();
        $invalidations = TopicSourceInvalidation::query()->useWritePdo()->whereIn('basis_key', $bases)->get()->groupBy('basis_key');
        $this->readInvalidations = $invalidations->map(fn ($rows) => $rows->pluck('article_id')->all())->all();
        $this->readInvalidatedAt = $invalidations->map(fn ($rows) => $rows->max('invalidated_at'))->all();
        $this->readSiteKey = $siteKey;
        try {
            return $read();
        } finally {
            [$this->readArticles,$this->readInvalidations,$this->readSiteKey,$this->readPolicy,$this->readProfile,$this->readInvalidatedAt] = $previous;
        }
    }

    public function __construct(
        private readonly TopicPayload $payloads,
        private readonly TopicFreshnessService $freshness,
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly ArticlePermalinkService $articlePermalinks,
        private readonly HostedSiteUrlGenerator $hostedUrls,
        private readonly ArticlePublicationEligibilityService $eligibility,
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function build(Topic $topic, array $payload, ?TopicRevision $revision = null, bool $preview = false): array
    {
        [$effective, $articles, $changed] = $this->filterSources($topic, $payload, $revision);
        $warnings = $changed ? ['来源文章已改变或不可见，关联的导语、摘要和评分已隐藏，请重新核实。'] : [];
        try {
            $snapshot = $revision?->freshness_snapshot_json ?? $revision?->payload['freshness_snapshot_json']
                ?? $this->freshness->snapshot($payload['freshness'], $articles, ($revision?->created_at ?? $topic->draft_content_composed_at) ? CarbonImmutable::instance($revision?->created_at ?? $topic->draft_content_composed_at) : null);
        } catch (ValidationException) {
            $snapshot = ['mode' => $payload['freshness']['mode'] ?? null, 'supported' => false];
        }
        if ($changed && ! in_array($snapshot['mode'] ?? null, ['none', 'evergreen', 'composed_at'], true)) {
            $snapshot['supported'] = false;
        }
        $temporal = $this->freshness->evaluate($snapshot);
        $title = $this->freshness->title($effective['title'], $snapshot, $temporal['freshness_status']);
        if ($temporal['suppress_claims']) {
            $warnings[] = $temporal['message'];
            $effective['intro'] = '';
            $effective['summary'] = ['one_sentence' => '', 'facts' => [], 'scope' => '', 'reading_advice' => ''];
            $effective['faq'] = [];
            $effective['basic_info'] = [];
            $effective['score'] = null;
            foreach ($effective['articles'] as &$entry) {
                $entry['reason'] = '';
            }
            unset($entry);
        }
        $profile = $this->isReading($topic) ? $this->readProfile : ($topic->site_key === 'primary' ? null : HostedSiteProfile::query()->with('channel')->find((int) substr($topic->site_key, 7)));
        $primaryPolicy = $this->readPolicy ?? ArticlePermalinkPolicy::fromRaw(SiteSetting::query()
            ->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'));
        $baseUrl = $profile === null
            ? rtrim((string) config('geoflow.site_url', config('app.url')), '/')
            : 'https://'.$profile->hostname;
        $articleViews = [];
        foreach ($effective['articles'] as $entry) {
            $article = $articles->find($entry['article_id']);
            if ($article === null) {
                continue;
            }
            $articleViews[] = [
                'id' => (int) $article->id, 'article_id' => (int) $article->id,
                'title' => (string) $article->title, 'excerpt' => (string) $article->excerpt,
                'url' => $profile !== null ? $this->hostedUrls->article($profile, $article)
                    : $baseUrl.$this->articlePermalinks->path($article, $primaryPolicy),
                'group' => $entry['group'], 'reason' => $entry['reason'],
                'published_at' => $article->published_at?->toIso8601String(),
            ];
        }
        $sourceHashes = $revision === null ? ($topic->draft_source_hashes ?? [])
            : $revision->articles->pluck('content_hash', 'article_id')->all();
        $score = $this->payloads->usableScore($effective, $articles->modelKeys(),
            $revision?->payload['_score_binding'] ?? ($revision === null ? $topic->draft_score_binding : null), $sourceHashes);
        if (($payload['score']['enabled'] ?? false) && $score === null) {
            $warnings[] = '评分依据、计算或有效期已失效，当前省略评分卡。';
        }
        $modifiedAt = $preview ? $topic->updated_at : $revision?->created_at;
        if (! $preview && $revision !== null && ! empty($revision->payload['_content_modified_at'])) {
            try {
                $contentModifiedAt = CarbonImmutable::parse($revision->payload['_content_modified_at']);
                if ($contentModifiedAt->lessThanOrEqualTo($revision->created_at)) {
                    $modifiedAt = $contentModifiedAt;
                }
            } catch (\Throwable) { /* Older or invalid snapshots retain the revision date. */
            }
        }
        if (! $preview && $topic->first_published_at !== null && ($modifiedAt === null || $modifiedAt->lessThan($topic->first_published_at))) {
            $modifiedAt = $topic->first_published_at;
        }
        if (! $preview && $revision !== null) {
            $changedAt = $changed ? ($this->isReading($topic) ? ($this->readInvalidatedAt['revision:'.$revision->id] ?? now()) : TopicSourceInvalidation::query()->useWritePdo()->where('topic_revision_id', $revision->id)->max('invalidated_at')) : null;
            $boundaries = [$changedAt];
            try {
                if ($temporal['freshness_status'] === 'expired') {
                    $boundaries[] = $this->freshness->expirationBoundary($snapshot);
                }
                $binding = $revision->payload['_score_binding'] ?? null;
                if (! $changed && $score === null && ($payload['score']['enabled'] ?? false) && $binding) {
                    $boundaries[] = $binding['expires_at'] ?? $this->payloads->scoreExpiresAt($payload, CarbonImmutable::parse($binding['rated_at']));
                }
            } catch (\Throwable) { /* Invalid optional evidence has no reliable transition date. */
            }
            foreach (array_filter($boundaries) as $boundary) {
                $boundary = CarbonImmutable::parse($boundary);
                if ($boundary->lessThanOrEqualTo(now()) && ($modifiedAt === null || $boundary->greaterThan($modifiedAt))) {
                    $modifiedAt = $boundary;
                }
            }
        }
        if (in_array($temporal['freshness_status'], ['expired', 'scheduled'], true)) {
            $effective['seo'] = ['title' => '', 'description' => ''];
        }
        $path = '/topics/'.$topic->slug;

        return [
            'id' => (int) $topic->id, 'site_key' => $topic->site_key, 'slug' => $topic->slug,
            'path' => $path, 'url' => $baseUrl.$path,
            'base_title' => $effective['title'], 'title' => $title, 'computed_title' => $title,
            'intro' => $effective['intro'], 'summary' => $effective['summary'], 'tags' => $effective['tags'],
            'articles' => $articleViews, 'article_count' => count($articleViews),
            'template_key' => $effective['template_key'], 'freshness' => $effective['freshness'],
            'freshness_snapshot_json' => $snapshot, 'freshness_status' => $temporal['freshness_status'],
            'event_hint' => $temporal['event_hint'], 'freshness_message' => $temporal['message'],
            'freshness_coverage' => $temporal['coverage_note'], 'freshness_next_transition_at' => $temporal['next_transition_at'],
            'public_allowed' => $temporal['public_allowed'] && ! $changed,
            'seo' => $this->safeSeo($effective), 'basic_info' => $effective['basic_info'], 'score' => $score, 'faq' => $effective['faq'] ?? [],
            'published_at' => $preview ? null : $topic->published_at?->toIso8601String(),
            'first_published_at' => $preview ? null : $topic->first_published_at?->toIso8601String(),
            'review_info' => $preview ? null : $this->reviewInfo($topic, $revision),
            'modified_at' => $modifiedAt?->toIso8601String(), 'timezone' => $snapshot['timezone'] ?? 'Asia/Shanghai',
            'warnings' => $warnings, 'noindex' => $preview, 'is_preview' => $preview,
        ];
    }

    /** @return array{method:string,label:string,reviewed_at:string}|null */
    private function reviewInfo(Topic $topic, ?TopicRevision $revision): ?array
    {
        if ($revision === null || $topic->public_revision_id !== $revision->id) {
            return null;
        }
        $review = $topic->review_records[$revision->id] ?? null;
        if (! is_array($review) || ($review['method'] ?? null) !== 'editorial' || empty($review['reviewed_at'])) {
            return null;
        }
        try {
            $reviewedAt = CarbonImmutable::parse($review['reviewed_at']);
            if ($reviewedAt->greaterThan(now())) {
                return null;
            }

            return ['method' => 'editorial', 'label' => '人工审核', 'reviewed_at' => $reviewedAt->toIso8601String()];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function effectivePayload(Topic $topic, array $payload, TopicRevision $revision): array
    {
        return $this->filterSources($topic, $payload, $revision)[0];
    }

    public function isEligible(Article $article, string $siteKey): bool
    {
        return $this->siteArticles->queryForSiteKey($siteKey)->useWritePdo()->whereKey($article->id)->exists()
            && $this->hasEligibleApproval($article);
    }

    /** Caller must obtain the article from the site-scoped public query. */
    public function isEligibleScoped(Article $article): bool
    {
        return $this->hasEligibleApproval($article);
    }

    /** @param array<string,mixed> $payload @return Collection<int,Article> */
    public function eligibleArticles(string $siteKey, array $payload, bool $lock = false): Collection
    {
        if (! $lock && $this->readArticles !== null && $this->readSiteKey === $siteKey) {
            return $this->readArticles->whereIn('id', array_column($payload['articles'], 'article_id'))->unique(fn ($a) => TopicService::bodyHash($a))->values();
        }
        $query = $this->siteArticles->queryForSiteKey($siteKey)->useWritePdo()
            ->whereIn('articles.review_status', ArticleWorkflow::PUBLISHABLE_REVIEW_STATUSES)
            ->whereIn('articles.id', array_column($payload['articles'], 'article_id'))
            ->with(['category', 'task' => fn ($q) => $q->useWritePdo(), 'latestRiskScan' => fn ($q) => $q->useWritePdo(), 'latestTopicReview' => fn ($q) => $q->useWritePdo()])->orderBy('articles.id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->filter(fn (Article $article): bool => $this->hasEligibleApproval($article))
            ->unique(fn (Article $article): string => TopicService::bodyHash($article))->values();
    }

    public function invalidateArticle(int $articleId): void
    {
        $article = Article::query()->useWritePdo()->with([
            'task' => fn ($query) => $query->useWritePdo(),
            'latestRiskScan' => fn ($query) => $query->useWritePdo(),
            'latestTopicReview' => fn ($query) => $query->useWritePdo(),
        ])->find($articleId);
        $sources = TopicRevisionArticle::query()->useWritePdo()->where('article_id', $articleId)->with([
            'revision' => fn ($query) => $query->useWritePdo(),
            'revision.topic' => fn ($query) => $query->useWritePdo(),
        ])->get();
        foreach ($sources as $source) {
            $topic = $source->revision?->topic;
            if ($topic === null) {
                continue;
            }
            if ($article === null || ! $this->isEligible($article, $topic->site_key)
                || ! hash_equals($source->content_hash, TopicService::contentHash($article))) {
                TopicSourceInvalidation::record((int) $topic->id, 'revision:'.$source->topic_revision_id,
                    $articleId, $article === null || ! $this->isEligible($article, $topic->site_key) ? 'source_ineligible' : 'source_changed', $source->topic_revision_id);
            }
        }
        foreach (Topic::withTrashed()->useWritePdo()->whereNotNull('draft_source_hashes->'.$articleId)->cursor() as $topic) {
            $hash = (string) ($topic->draft_source_hashes[$articleId] ?? '');
            if ($article === null || ! $this->isEligible($article, $topic->site_key)
                || ! hash_equals($hash, TopicService::contentHash($article))) {
                TopicSourceInvalidation::record((int) $topic->id, 'draft:'.$topic->id.':'.$hash,
                    $articleId, $article === null || ! $this->isEligible($article, $topic->site_key) ? 'source_ineligible' : 'source_changed');
            }
        }
    }

    private function safeSeo(array $payload): array
    {
        try {
            app(TopicFreshnessService::class)->assertSearchClaims($payload);

            return $payload['seo'] ?? ['title' => '', 'description' => ''];
        } catch (ValidationException) {
            return ['title' => '', 'description' => ''];
        }
    }

    private function hasEligibleApproval(Article $article): bool
    {
        if ($article->latestRiskScan?->status === 'blocked') {
            return false;
        }

        $approval = $article->relationLoaded('latestTopicReview') ? ($article->latestTopicReview === null || ($article->latestTopicReview->review_status === 'approved' && ($article->latestTopicReview->content_hash === null || hash_equals($article->latestTopicReview->content_hash, $article->reviewContentHash())))) : $this->eligibility->hasCurrentApproval($article);

        return $article->review_status === 'approved' ? $approval
            : ($article->review_status === 'auto_approved' && ! $this->eligibility->manualReviewRequired($article));
    }

    /** @param array<string,mixed> $payload @return array{array<string,mixed>,Collection<int,Article>,bool} */
    private function filterSources(Topic $topic, array $payload, ?TopicRevision $revision): array
    {
        $entries = $payload['articles'];
        $snapshots = $revision?->loadMissing('articles')->articles->keyBy('article_id');
        $ids = array_column($entries, 'article_id');
        if ($snapshots !== null) {
            $ids = array_values(array_intersect($ids, $snapshots->keys()->all()));
        }
        $articles = $this->eligibleArticles($topic->site_key, ['articles' => array_map(static fn (int $id): array => ['article_id' => $id], $ids)]);
        $sourceHashes = $snapshots === null ? ($topic->draft_source_hashes ?? []) : $snapshots->pluck('content_hash', 'article_id')->all();
        $basisKeys = $revision === null
            ? array_map(static fn (string $hash): string => 'draft:'.$topic->id.':'.$hash, array_values($sourceHashes))
            : ['revision:'.$revision->id];
        foreach ($sourceHashes as $articleId => $hash) {
            $article = $articles->find((int) $articleId);
            if ($article === null || ! hash_equals((string) $hash, TopicService::contentHash($article))) {
                TopicSourceInvalidation::record((int) $topic->id,
                    $revision === null ? 'draft:'.$topic->id.':'.$hash : 'revision:'.$revision->id,
                    (int) $articleId, $article === null ? 'source_ineligible' : 'source_changed', $revision?->id);
            }
        }
        $invalidIds = $this->readArticles !== null && $this->readSiteKey === $topic->site_key ? array_merge(...array_map(fn ($key) => $this->readInvalidations[$key] ?? [], $basisKeys)) : TopicSourceInvalidation::query()->useWritePdo()->whereIn('basis_key', $basisKeys)->pluck('article_id')->all();
        $invalidIds = array_merge($invalidIds, array_keys(array_filter($sourceHashes, fn ($hash, $id) => ! $articles->find((int) $id) || ! hash_equals((string) $hash, TopicService::contentHash($articles->find((int) $id))), ARRAY_FILTER_USE_BOTH)));
        $unchangedIds = [];
        foreach ($articles as $article) {
            if (! in_array((int) $article->id, $invalidIds, true)
                && hash_equals((string) ($sourceHashes[$article->id] ?? ''), TopicService::contentHash($article))) {
                $unchangedIds[] = (int) $article->id;
            }
        }
        $changed = count($unchangedIds) !== count($entries);
        $payload['articles'] = array_values(array_filter($entries, fn (array $entry): bool => $articles->contains('id', $entry['article_id'])));
        foreach ($payload['articles'] as &$entry) {
            if (! in_array($entry['article_id'], $unchangedIds, true)) {
                $entry['reason'] = '';
            }
        }
        unset($entry);
        $supported = fn (array $claim): bool => ($claim['article_ids'] ?? []) !== []
            && array_diff($claim['article_ids'], $unchangedIds) === [];
        $payload['summary']['facts'] = array_values(array_filter($payload['summary']['facts'] ?? [], fn (array $claim): bool => $supported($claim) && app(TopicEvidenceService::class)->validFact($claim, $articles)));
        $payload['faq'] = array_values(array_filter($payload['faq'] ?? [], $supported));
        if ($changed) {
            $payload['intro'] = '';
            $payload['summary']['one_sentence'] = '';
            $payload['summary']['scope'] = '';
            $payload['summary']['reading_advice'] = '';
            $payload['basic_info'] = [];
            $payload['score'] = null;
            $payload['seo'] = ['title' => '', 'description' => ''];
        }
        if (($payload['score']['enabled'] ?? false) && array_filter($payload['score']['evidence'] ?? [], $supported) !== ($payload['score']['evidence'] ?? [])) {
            $payload['score'] = null;
        }

        return [$payload, $articles, $changed];
    }
}
