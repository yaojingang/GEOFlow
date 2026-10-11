<?php

namespace App\Services\Site;

use App\Jobs\RefreshLinkPoolBatch;
use App\Models\Article;
use App\Services\LinkPool\LinkPoolTargetService;
use App\Support\Site\LinkPoolThemeValidationContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LinkPoolRecommendationService
{
    public function __construct(private readonly LinkPoolSiteService $sites) {}

    /** @param list<int> $relatedIds @return array{heading:string,items:list<array<string,mixed>>}|null */
    public function forArticle(Article $source, array $relatedIds = []): ?array
    {
        return $this->forSiteArticle($this->sites->currentKey(), $source, $relatedIds);
    }

    public function sampleSource(string $siteKey, int $campaignId = 0, bool $draft = false, string $titleSearch = ''): ?Article
    {
        $campaign = $campaignId ? DB::table('link_campaigns')->where('id', $campaignId)->where('source_site_key', $siteKey)->first() : null;
        if ($campaignId && $campaign === null) {
            return null;
        }
        if ($campaign && $draft) {
            $payload = json_decode((string) DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->value('payload_json'), true);
            if (is_array($payload)) {
                $campaign->source_rules_json = json_encode($payload);
                $campaign->exclusions_json = json_encode($payload['exclusion_ids'] ?? []);
                $campaign->draft_source_ids = $payload['source_article_ids'] ?? [];
            }
        }
        $query = $this->sites->query($siteKey)->select(['articles.id', 'articles.category_id', 'articles.keywords'])
            ->when($titleSearch !== '', fn ($query) => $query->where('articles.title', 'like', '%'.$titleSearch.'%'));
        $targetIds = $payload['target_ids'] ?? ($campaign ? DB::table('link_campaign_targets')->where('campaign_id', $campaignId)->pluck('target_id')->all() : []);
        $targetArticles = DB::table('link_pool_targets')->whereIn('id', $targetIds)->where('target_site_key', $siteKey)->pluck('article_id')->all();
        if ($campaign) {
            $rules = json_decode((string) $campaign->source_rules_json, true) ?: [];
            if (($rules['scope'] ?? '') === 'articles') {
                $query->whereIn('articles.id', $campaign->draft_source_ids
                    ?? DB::table('link_campaign_sources')->where('campaign_id', $campaignId)->pluck('source_article_id'));
            } elseif (($rules['scope'] ?? '') === 'category') {
                $query->where('articles.category_id', (int) ($rules['category_id'] ?? 0));
            } elseif (($rules['scope'] ?? '') === 'keyword') {
                $query->whereRaw('LOWER(articles.keywords) LIKE ?', ['%'.mb_strtolower((string) ($rules['keyword'] ?? '')).'%']);
            }
        }
        $fallback = null;
        foreach ($query->orderByDesc('published_at')->orderByDesc('articles.id')->cursor() as $article) {
            if ($campaign === null || $this->matchesSource($campaign, $article)) {
                $fallback ??= $article;
                if (! in_array($article->id, $targetArticles)) {
                    return $article;
                }
            }
        }

        return $fallback;
    }

    /** @param list<int> $relatedIds @return array{heading:string,items:list<array<string,mixed>>}|null */
    public function forSiteArticle(string $siteKey, Article $source, array $relatedIds = [], bool $refreshIfStale = true): ?array
    {
        $validation = app(LinkPoolThemeValidationContext::class);
        if ($validation->active()) {
            return $validation->block();
        }
        if ($this->sites->emergencyDisabled() || ! $this->sites->canPublish($siteKey)) {
            return null;
        }
        $settings = $this->sites->settings($siteKey);
        if (! $settings['enabled'] || ! $this->sites->themeSupportsRecommendations($siteKey)
            || $this->sites->article($siteKey, (int) $source->id) === null) {
            return null;
        }

        $campaigns = $this->campaigns($siteKey, $source);
        if ($campaigns === []) {
            return null;
        }
        $fingerprint = $this->fingerprint($campaigns, $settings, $source);
        $head = DB::table('link_selection_heads')
            ->where('source_site_key', $siteKey)
            ->where('source_article_id', (int) $source->id)
            ->first();
        $batch = $head ? DB::table('link_selection_batches')->find((int) $head->batch_id) : null;
        if ($refreshIfStale && ($batch === null || $batch->rule_fingerprint !== $fingerprint
            || Carbon::parse($batch->rotate_after)->isPast())) {
            $this->enqueueRefresh($siteKey, (int) $source->id);
            $head = DB::table('link_selection_heads')->where('source_site_key', $siteKey)
                ->where('source_article_id', (int) $source->id)->first();
            $batch = $head ? DB::table('link_selection_batches')->find((int) $head->batch_id) : null;
        }
        if ($batch === null || $batch->rule_fingerprint !== $fingerprint) {
            return null;
        }
        if (Carbon::parse($batch->generated_at)->addHours($settings['rotation_hours'] * 2)->isPast()) {
            return null;
        }

        $placements = DB::table('link_placements')->where('batch_id', (int) $batch->id)->orderBy('slot')->get();
        $visible = $this->visiblePlacements($siteKey, (int) $source->id, $placements, $relatedIds,
            $settings['default_limit'], $this->placementContext($placements));
        $items = [];
        foreach ($visible as [$placement, $target, $url, $external]) {
            $items[] = [
                'placement_id' => (int) $placement->id,
                'batch_id' => (int) $batch->id,
                'title' => (string) $target->display_title,
                'url' => $url,
                'target_id' => (int) $target->id,
                'target_type' => $target->target_type,
                'target_site_key' => (string) $placement->target_site_key,
                'target_site_name' => $external ? (string) parse_url($url, PHP_URL_HOST)
                    : ($this->sites->profile((string) $placement->target_site_key)?->channel?->name ?? '主站'),
                'reason' => (string) $placement->reason_code,
                'score' => (int) $placement->score,
                'campaign_id' => (int) $placement->campaign_id,
            ];
        }

        if ($items === []) {
            return null;
        }

        return [
            'heading' => $settings['heading'], 'items' => $items,
            'batch_id' => (int) $batch->id, 'rotate_after' => (string) $batch->rotate_after,
        ];
    }

    private function placementContext(Collection $placements): array
    {
        $targetRows = DB::table('link_pool_targets')->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($placements): void {
                $query->whereIn('id', $placements->pluck('target_id')->filter()->all());
                $legacy = $placements->whereNull('target_id');
                if ($legacy->isNotEmpty()) {
                    $query->orWhere(fn ($old) => $old->whereIn('target_site_key', $legacy->pluck('target_site_key')->all())
                        ->whereIn('article_id', $legacy->pluck('target_article_id')->all()));
                }
            })->get()->keyBy('id');
        $articlesBySite = [];
        $publishable = [];
        foreach ($placements->groupBy('target_site_key') as $targetSiteKey => $sitePlacements) {
            if ($targetSiteKey === 'external') {
                continue;
            }
            $publishable[$targetSiteKey] = $this->sites->canPublish((string) $targetSiteKey);
            $articlesBySite[$targetSiteKey] = $publishable[$targetSiteKey]
                ? $this->sites->query((string) $targetSiteKey)->with('category:id,slug')
                    ->whereIn('articles.id', $sitePlacements->pluck('target_article_id')->all())
                    ->get(['articles.id', 'articles.slug', 'articles.category_id', 'articles.created_at'])->keyBy('id')
                : collect();
        }
        $crossSite = $placements->filter(static fn ($placement): bool => $placement->target_site_key !== 'external'
            && $placement->target_site_key !== $placement->source_site_key);
        $approvals = $crossSite->isEmpty() ? collect() : DB::table('link_campaign_targets')
            ->whereIn('campaign_id', $crossSite->pluck('campaign_id'))
            ->whereIn('target_id', $targetRows->keys())->where('cross_site_review_status', 'approved')
            ->get(['campaign_id', 'target_id'])->keyBy(static fn ($row): string => $row->campaign_id.':'.$row->target_id);
        $urls = collect();

        return compact('targetRows', 'articlesBySite', 'publishable', 'approvals', 'urls');
    }

    /** @param list<int> $relatedIds @return list<array{object,object,string,bool}> */
    private function visiblePlacements(string $siteKey, int $sourceId, Collection $placements, array $relatedIds, int $limit, array $context): array
    {
        ['targetRows' => $targetRows, 'articlesBySite' => $articlesBySite, 'publishable' => $publishable, 'approvals' => $approvals, 'urls' => $urls] = $context;
        $visible = [];
        foreach ($placements as $placement) {
            if (count($visible) >= $limit) {
                break;
            }
            if (in_array((int) $placement->target_article_id, $relatedIds, true)
                && $placement->target_site_key === $siteKey) {
                continue;
            }
            $target = $placement->target_id ? $targetRows->get($placement->target_id)
                : $targetRows->first(fn ($row): bool => $row->target_site_key === $placement->target_site_key && (int) $row->article_id === (int) $placement->target_article_id);
            if ($target === null) {
                continue;
            }
            $external = LinkPoolTargetService::isExternal($target);
            if (! $external && (! $publishable[$placement->target_site_key]
                || $placement->target_site_key !== $siteKey && ! $approvals->has($placement->campaign_id.':'.$target->id))) {
                continue;
            }
            $article = $external ? null : $articlesBySite[$placement->target_site_key]->get((int) $placement->target_article_id);
            $urlKey = $external ? 'external:'.$target->id : $placement->target_site_key.':'.$placement->target_article_id;
            if (! $urls->has($urlKey)) {
                $urls->put($urlKey, $external ? app(LinkPoolTargetService::class)->normalizeUrl($target->input_url)
                    : ($article ? $this->sites->url($placement->target_site_key, $article) : null));
            }
            $url = $urls->get($urlKey);
            if ($url === null || ! $external && (int) $article->id === $sourceId && $placement->target_site_key === $siteKey) {
                continue;
            }
            $visible[] = [$placement, $target, $url, $external];
        }

        return $visible;
    }

    private function enqueueRefresh(string $siteKey, int $articleId): void
    {
        $job = (new RefreshLinkPoolBatch($siteKey, $articleId))->onQueue('default');
        $lock = new UniqueLock(app(Repository::class));
        $acquired = false;
        try {
            $acquired = $lock->acquire($job);
            if ($acquired) {
                Bus::dispatch($job);
            }
        } catch (Throwable $exception) {
            if ($acquired) {
                try {
                    $lock->release($job);
                } catch (Throwable $releaseException) {
                    report($releaseException);
                }
            }
            report($exception);
        }
    }

    /** @param list<int> $relatedIds @return array{heading:string,items:list<array<string,mixed>>} */
    public function preview(string $siteKey, Article $source, array $relatedIds = []): array
    {
        $settings = $this->sites->settings($siteKey);

        return $this->forSiteArticle($siteKey, $source, $relatedIds, false)
            ?? ['heading' => $settings['heading'], 'items' => []];
    }

    /** @param list<int> $relatedIds @return array{heading:string,items:list<array<string,mixed>>,preview_status?:string} */
    public function previewDraft(string $siteKey, Article $source, int $campaignId, array $relatedIds = []): array
    {
        $campaign = DB::table('link_campaigns')->where('id', $campaignId)->where('source_site_key', $siteKey)->first();
        $draft = DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->first();
        if ($campaign === null || $draft === null) {
            return ['heading' => $this->sites->settings($siteKey)['heading'], 'items' => [], 'preview_status' => 'missing_draft'];
        }
        $payload = json_decode((string) $draft->payload_json, true) ?: [];
        $synthetic = (object) [
            'id' => $campaignId, 'mode' => $payload['mode'] ?? 'manual',
            'priority' => (int) ($payload['priority'] ?? 0), 'limit' => (int) ($payload['limit'] ?? 4),
            'revision' => (int) $campaign->revision + 1,
            'source_rules_json' => json_encode(['scope' => $payload['scope'] ?? 'all',
                'category_id' => $payload['category_id'] ?? 0, 'keyword' => $payload['keyword'] ?? '']),
            'exclusions_json' => json_encode($payload['exclusion_ids'] ?? []),
            'draft_source_ids' => $payload['source_article_ids'] ?? [],
            'draft_targets' => collect($payload['target_ids'] ?? [])->map(function ($id) use ($payload, $siteKey) {
                $target = DB::table('link_pool_targets')->find((int) $id);

                return (object) [
                    'target_id' => (int) $id,
                    'weight' => (int) ($payload['weights'][$id] ?? 1),
                    'pin_order' => in_array((int) $id, $payload['pinned_ids'] ?? [], true)
                        ? array_search((int) $id, $payload['pinned_ids'], true) + 1 : null,
                    'cross_site_review_status' => $target && (LinkPoolTargetService::isExternal($target) || $target->target_site_key === $siteKey) ? 'not_required'
                        : (in_array((int) $id, $payload['approved_cross_site_ids'] ?? [], true)
                            && trim((string) ($payload['review_reasons'][$id] ?? '')) !== '' ? 'approved' : 'pending'),
                ];
            })->all(),
        ];
        $campaigns = array_values(array_filter($this->campaigns($siteKey, $source),
            static fn (object $row): bool => (int) $row->id !== $campaignId));
        if ($this->matchesSource($synthetic, $source)) {
            $campaigns[] = $synthetic;
        }
        usort($campaigns, static fn (object $a, object $b): int => [$b->priority, $a->id] <=> [$a->priority, $b->id]);
        $settings = $this->sites->settings($siteKey);

        return $this->previewItems($settings, $this->select($siteKey, $source, $campaigns, $relatedIds, 1, true));
    }

    /** @param array<string,mixed> $settings @param list<array<string,mixed>> $selected @return array{heading:string,items:list<array<string,mixed>>} */
    private function previewItems(array $settings, array $selected): array
    {
        $items = [];
        foreach (array_slice($selected, 0, $settings['default_limit']) as $row) {
            $items[] = [
                'title' => $row['title'],
                'url' => $row['url'],
                'target_id' => $row['target_id'],
                'target_type' => $row['target_type'],
                'target_site_name' => $row['target_site_name'],
                'target_site_key' => $row['target_site_key'],
                'reason' => $row['reason_code'],
                'score' => $row['score'],
                'campaign_id' => $row['campaign_id'],
            ];
        }

        return ['heading' => $settings['heading'], 'items' => $items];
    }

    public function refresh(string $siteKey, int $articleId): void
    {
        if (! $this->sites->canPublish($siteKey) || $this->sites->emergencyDisabled()) {
            return;
        }
        $source = $this->sites->article($siteKey, $articleId);
        if ($source === null || ! $this->sites->settings($siteKey)['enabled']
            || ! $this->sites->themeSupportsRecommendations($siteKey)) {
            return;
        }
        $campaigns = $this->campaigns($siteKey, $source);
        if ($campaigns === []) {
            return;
        }
        $settings = $this->sites->settings($siteKey);
        $fingerprint = $this->fingerprint($campaigns, $settings, $source);
        $relatedIds = $this->sites->query($siteKey)
            ->where('category_id', $source->category_id)
            ->whereKeyNot($articleId)
            ->orderByDesc('published_at')->orderByDesc('id')->limit(6)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        DB::transaction(function () use ($siteKey, $articleId, $source, $relatedIds, $fingerprint, $settings): void {
            DB::table('link_selection_heads')->insertOrIgnore([
                'source_site_key' => $siteKey,
                'source_article_id' => $articleId,
                'batch_id' => 0,
                'revision' => 0,
            ]);
            $head = DB::table('link_selection_heads')
                ->where('source_site_key', $siteKey)
                ->where('source_article_id', $articleId)
                ->lockForUpdate()->first();
            $previous = $head && $head->batch_id
                ? DB::table('link_selection_batches')->find((int) $head->batch_id) : null;
            if ($previous !== null && $previous->rule_fingerprint === $fingerprint
                && Carbon::parse($previous->rotate_after)->isFuture()) {
                return;
            }
            if ($this->sites->article($siteKey, $articleId) === null
                || ! $this->sites->canPublish($siteKey)
                || $this->sites->emergencyDisabled()
                || $this->fingerprint($this->campaigns($siteKey, $source), $this->sites->settings($siteKey), $source) !== $fingerprint) {
                return;
            }
            $sequence = (int) ($previous->sequence ?? 0) + 1;
            $selected = $this->select($siteKey, $source, $this->campaigns($siteKey, $source), $relatedIds, $sequence);
            if ($this->fingerprint($this->campaigns($siteKey, $source), $this->sites->settings($siteKey), $source) !== $fingerprint) {
                return;
            }
            $batchId = DB::table('link_selection_batches')->insertGetId([
                'source_site_key' => $siteKey,
                'source_article_id' => $articleId,
                'rule_fingerprint' => $fingerprint,
                'sequence' => $sequence,
                'generated_at' => now(),
                'rotate_after' => now()->addHours($settings['rotation_hours']),
            ]);
            foreach ($selected as $index => $item) {
                DB::table('link_placements')->insert([
                    'batch_id' => $batchId,
                    'source_site_key' => $siteKey,
                    'source_article_id' => $articleId,
                    'target_site_key' => $item['target_site_key'],
                    'target_article_id' => $item['target_article_id'],
                    'target_id' => $item['target_id'],
                    'campaign_id' => $item['campaign_id'],
                    'slot' => $index + 1,
                    'reason_code' => $item['reason_code'],
                    'score' => $item['score'],
                    'selected_at' => now(),
                ]);
            }
            DB::table('link_selection_heads')
                ->where('source_site_key', $siteKey)->where('source_article_id', $articleId)
                ->update(['batch_id' => $batchId, 'revision' => $sequence]);
        }, 3);
    }

    /** @return list<object> */
    private function campaigns(string $siteKey, Article $source): array
    {
        $rows = DB::table('link_campaigns')
            ->where('source_site_key', $siteKey)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('priority')->orderBy('id')->get();

        return $rows->filter(fn (object $campaign): bool => $this->matchesSource($campaign, $source))->all();
    }

    private function matchesSource(object $campaign, Article $source): bool
    {
        $rules = json_decode((string) $campaign->source_rules_json, true) ?: [];
        $exclusions = json_decode((string) $campaign->exclusions_json, true) ?: [];
        if (in_array((int) $source->id, array_map('intval', $exclusions), true)) {
            return false;
        }

        return match ($rules['scope'] ?? 'all') {
            'category' => (int) $source->category_id === (int) ($rules['category_id'] ?? 0),
            'articles' => isset($campaign->draft_source_ids)
                ? in_array((int) $source->id, array_map('intval', $campaign->draft_source_ids), true)
                : DB::table('link_campaign_sources')->where('campaign_id', $campaign->id)
                    ->where('source_article_id', $source->id)->exists(),
            'keyword' => in_array(mb_strtolower(trim((string) ($rules['keyword'] ?? ''))), $this->words((string) $source->keywords), true),
            default => true,
        };
    }

    /** @param list<object> $campaigns @return list<array<string,mixed>> */
    private function select(string $siteKey, Article $source, array $campaigns, array $relatedIds, int $sequence, bool $preview = false): array
    {
        $selected = [];
        $used = [];
        $pinCount = 0;
        $crossCount = 0;
        foreach ($campaigns as $campaign) {
            $explicit = isset($campaign->draft_targets)
                ? collect($campaign->draft_targets)->keyBy('target_id')
                : DB::table('link_campaign_targets')->where('campaign_id', $campaign->id)->get()->keyBy('target_id');
            $targets = DB::table('link_pool_targets')->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            if ($explicit->isNotEmpty() || $campaign->mode === 'manual') {
                $targets->whereIn('id', $explicit->keys()->all());
            } else {
                $targets->where(fn ($query) => $query->where('target_site_key', $siteKey)->orWhere('target_type', 'external'));
            }
            $candidates = [];
            $targets->chunkById(500, function ($targetRows) use (
                $siteKey, $preview, $campaign, $explicit, $source, $relatedIds, $sequence, &$candidates,
            ): void {
                $articlesBySite = [];
                $publishable = [];
                foreach ($targetRows->groupBy('target_site_key') as $candidateSite => $siteTargets) {
                    if ($candidateSite === 'external') {
                        continue;
                    }
                    $publishable[$candidateSite] = $this->sites->canPublish((string) $candidateSite)
                        || $preview && $candidateSite === $siteKey;
                    $articlesBySite[$candidateSite] = $publishable[$candidateSite]
                        ? $this->sites->query((string) $candidateSite)->whereIn('articles.id', $siteTargets->pluck('article_id')->all())->get()->keyBy('id')
                        : collect();
                }
                foreach ($targetRows as $target) {
                    $external = LinkPoolTargetService::isExternal($target);
                    $cross = ! $external && $target->target_site_key !== $siteKey;
                    $relation = $explicit->get($target->id);
                    if ($cross && ($campaign->mode !== 'manual' || $relation?->cross_site_review_status !== 'approved')) {
                        continue;
                    }
                    if ($target->target_site_key === $siteKey && ((int) $target->article_id === (int) $source->id
                        || in_array((int) $target->article_id, $relatedIds, true))) {
                        continue;
                    }
                    $article = $external ? null : $articlesBySite[$target->target_site_key]->get((int) $target->article_id);
                    $url = $external ? app(LinkPoolTargetService::class)->normalizeUrl($target->input_url)
                        : ($article ? $this->sites->url($target->target_site_key, $article) : null);
                    if ($url === null || ! $external && ! $publishable[$target->target_site_key]) {
                        continue;
                    }
                    $score = $external ? $this->externalScore($source, $target) : $this->score($source, $article, (int) $target->priority);
                    $pin = $relation?->pin_order !== null;
                    $weight = max(1, (int) ($relation?->weight ?? $target->priority));
                    $u = max(0.000001, hexdec(substr(hash('sha256', $siteKey.'|'.$source->id.'|'.$campaign->id.'|'.$campaign->revision.'|'.$sequence.'|'.$target->id), 0, 8)) / 4294967296);
                    $candidates[] = [
                        'target_site_key' => (string) $target->target_site_key,
                        'target_article_id' => $external ? null : (int) $target->article_id,
                        'target_id' => (int) $target->id,
                        'target_type' => $target->target_type,
                        'url' => $url,
                        'target_site_name' => $external ? (string) parse_url($url, PHP_URL_HOST)
                            : ($this->sites->profile($target->target_site_key)?->channel?->name ?? '主站'),
                        'campaign_id' => (int) $campaign->id,
                        'title' => (string) $target->display_title,
                        'score' => $score,
                        'published_at' => $article?->published_at?->getTimestamp() ?? 0,
                        'pin' => $pin,
                        'pin_order' => (int) ($relation?->pin_order ?? 255),
                        'random_key' => -log($u) / $weight,
                    ];
                }
            });
            usort($candidates, function (array $a, array $b) use ($campaign): int {
                if ($campaign->mode === 'manual') {
                    return [$a['pin_order'], $a['target_id']] <=> [$b['pin_order'], $b['target_id']];
                }
                if ($campaign->mode === 'random') {
                    return $a['random_key'] <=> $b['random_key'];
                }
                if ($campaign->mode === 'mixed' && $a['pin'] !== $b['pin']) {
                    return $a['pin'] ? -1 : 1;
                }
                if ($campaign->mode === 'mixed' && $a['pin'] && $b['pin']) {
                    return [$a['pin_order'], $a['target_id']] <=> [$b['pin_order'], $b['target_id']];
                }
                if ($campaign->mode === 'mixed' && $a['score'] <= 0 && $b['score'] <= 0) {
                    return $a['random_key'] <=> $b['random_key'];
                }

                return [$b['score'], $b['published_at'], $b['target_id']]
                    <=> [$a['score'], $a['published_at'], $a['target_id']];
            });
            $campaignCount = 0;
            foreach ($candidates as $item) {
                if (count($selected) >= 6 || $campaignCount >= (int) $campaign->limit) {
                    break;
                }
                $key = (string) $item['target_id'];
                if (isset($used[$key]) || ($item['pin'] && $pinCount >= 2)
                    || ($item['target_type'] !== 'external' && $item['target_site_key'] !== $siteKey && $crossCount >= 1)) {
                    continue;
                }
                if ($campaign->mode === 'related' && $item['score'] <= 0) {
                    continue;
                }
                $item['reason_code'] = $item['pin'] ? 'pinned'
                    : ($campaign->mode === 'manual' ? 'manual'
                        : ($campaign->mode === 'random' || $campaign->mode === 'mixed' && $item['score'] <= 0 ? 'random' : 'related'));
                $selected[] = $item;
                $used[$key] = true;
                $campaignCount++;
                $pinCount += (int) $item['pin'];
                $crossCount += (int) ($item['target_type'] !== 'external' && $item['target_site_key'] !== $siteKey);
            }
        }

        return $selected;
    }

    private function score(Article $source, Article $target, int $priority): int
    {
        $common = array_intersect($this->words((string) $source->keywords), $this->words((string) $target->keywords));

        $relevance = min(3, count($common)) * 5
            + ($source->category_id !== null && (int) $source->category_id === (int) $target->category_id ? 3 : 0);

        return $relevance === 0 ? 0 : $relevance
            + ($target->published_at && Carbon::parse($target->published_at)->greaterThan(now()->subDays(30)) ? 1 : 0)
            + min(2, max(0, $priority));
    }

    private function externalScore(Article $source, object $target): int
    {
        $common = array_intersect($this->words((string) $source->keywords), $this->words((string) $target->topic));

        return $common === [] ? 0 : min(3, count($common)) * 5 + min(2, (int) $target->priority);
    }

    /** @return list<string> */
    private function words(string $keywords): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $word): string => mb_strtolower(trim($word)),
            preg_split('/[,，、\n]+/u', $keywords) ?: [],
        ))));
    }

    private function crossSiteApproved(int $campaignId, int $targetId): bool
    {
        return DB::table('link_campaign_targets')->where('campaign_id', $campaignId)
            ->where('target_id', $targetId)->where('cross_site_review_status', 'approved')->exists();
    }

    /** @param list<string> $siteKeys @return list<int> */
    public function renderedPlacementIds(array $siteKeys): array
    {
        if ($this->sites->emergencyDisabled()) {
            return [];
        }
        $siteKeys = array_values(array_filter($siteKeys, fn (string $siteKey): bool => $this->sites->canPublish($siteKey)
            && $this->sites->settings($siteKey)['enabled'] && $this->sites->themeSupportsRecommendations($siteKey)));
        $ids = [];
        foreach (array_chunk($this->currentBatchIds($siteKeys), 500) as $batchIds) {
            $batches = DB::table('link_selection_batches')->whereIntegerInRaw('id', $batchIds)
                ->get(['id', 'source_site_key', 'source_article_id']);
            $placements = DB::table('link_placements')->whereIntegerInRaw('batch_id', $batchIds)->orderBy('slot')->get();
            $byBatch = $placements->groupBy('batch_id');
            $context = $this->placementContext($placements);
            foreach ($batches->groupBy('source_site_key') as $siteKey => $siteBatches) {
                $settings = $this->sites->settings($siteKey);
                $sources = $this->sites->query($siteKey)->whereIn('articles.id', $siteBatches->pluck('source_article_id'))
                    ->get(['articles.id', 'articles.category_id'])->keyBy('id');
                $related = $this->relatedArticlesByCategory($siteKey, $sources);
                foreach ($siteBatches as $batch) {
                    $source = $sources->get($batch->source_article_id);
                    if ($source === null) {
                        continue;
                    }
                    $relatedIds = $related->get($source->category_id, collect())->pluck('id')
                        ->reject(static fn ($id): bool => (int) $id === (int) $source->id)
                        ->take(6)->map(static fn ($id): int => (int) $id)->all();
                    foreach ($this->visiblePlacements($siteKey, (int) $source->id, $byBatch->get($batch->id, collect()),
                        $relatedIds, $settings['default_limit'], $context) as [$placement]) {
                        $ids[] = (int) $placement->id;
                    }
                }
            }
        }

        return $ids;
    }

    private function relatedArticlesByCategory(string $siteKey, Collection $sources): Collection
    {
        $categories = $sources->pluck('category_id')->unique();
        $ranked = $this->sites->query($siteKey)->where(function ($query) use ($categories): void {
            $query->whereIn('articles.category_id', $categories->filter(static fn ($id): bool => $id !== null));
            if ($categories->contains(null)) {
                $query->orWhereNull('articles.category_id');
            }
        })->select(['articles.id', 'articles.category_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY articles.category_id ORDER BY articles.published_at DESC, articles.id DESC) AS related_rank');

        return DB::query()->fromSub($ranked->toBase(), 'related_articles')
            ->where('related_rank', '<=', 7)->orderBy('related_rank')->get()->groupBy('category_id');
    }

    /** @param list<string> $siteKeys @return list<int> */
    public function currentBatchIds(array $siteKeys): array
    {
        $ids = [];
        $libraryRevision = app(LinkPoolTargetService::class)->libraryRevision();
        foreach ($siteKeys as $siteKey) {
            $settings = $this->sites->settings($siteKey);
            $campaigns = DB::table('link_campaigns')->where('source_site_key', $siteKey)->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->orderByDesc('priority')->orderBy('id')->get();
            $sources = DB::table('link_campaign_sources')->whereIn('campaign_id', $campaigns->pluck('id'))->get()->groupBy('campaign_id');
            foreach ($campaigns as $campaign) {
                $campaign->draft_source_ids = $sources->get($campaign->id, collect())->pluck('source_article_id')->all();
            }
            DB::table('link_selection_heads as heads')
                ->join('link_selection_batches as batches', 'batches.id', '=', 'heads.batch_id')
                ->where('heads.source_site_key', $siteKey)
                ->where('batches.generated_at', '>=', now()->subHours($settings['rotation_hours'] * 2))
                ->select('heads.source_article_id', 'batches.id', 'batches.rule_fingerprint')
                ->chunkById(500, function ($chunk) use ($siteKey, $campaigns, $settings, $libraryRevision, &$ids): void {
                    $articles = $this->sites->query($siteKey)->whereIn('articles.id', $chunk->pluck('source_article_id'))
                        ->get(['articles.id', 'articles.category_id', 'articles.keywords'])->keyBy('id');
                    foreach ($chunk as $head) {
                        $source = $articles->get($head->source_article_id);
                        if ($source === null) {
                            continue;
                        }
                        $matching = $campaigns->filter(fn ($campaign) => $this->matchesSource($campaign, $source))->all();
                        if ($matching !== [] && $head->rule_fingerprint === $this->fingerprint($matching, $settings, $source, $libraryRevision)) {
                            $ids[] = (int) $head->id;
                        }
                    }
                }, 'batches.id', 'id');
        }

        return $ids;
    }

    /** @param list<object> $campaigns */
    private function fingerprint(array $campaigns, array $settings, Article $source, ?int $libraryRevision = null): string
    {
        return hash('sha256', json_encode([
            array_map(static fn (object $campaign): array => [(int) $campaign->id, (int) $campaign->revision], $campaigns),
            $settings['default_limit'], $settings['rotation_hours'],
            $libraryRevision ?? app(LinkPoolTargetService::class)->libraryRevision(),
            [$source->category_id, $source->keywords],
        ]));
    }
}
