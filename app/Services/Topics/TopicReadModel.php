<?php

namespace App\Services\Topics;

use App\Models\Topic;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class TopicReadModel
{
    private array $requestViews = [];

    public function reset(): void
    {
        $this->requestViews = [];
    }

    public function __construct(private readonly TopicService $topics, private readonly TopicSiteSettings $settings) {}

    /** @return Collection<int,array<string,mixed>> */
    public function all(string $siteKey, array $filters = [], ?int $limit = null): Collection
    {
        $cacheKey = json_encode([spl_object_id(request()), $siteKey, $filters, $limit], JSON_THROW_ON_ERROR);
        if (isset($this->requestViews[$cacheKey])) {
            return $this->requestViews[$cacheKey];
        }
        if (! Schema::hasTable('topics') || ! $this->settings->get($siteKey)['enabled'] || app(TopicNamespaceGuard::class)->conflicts($siteKey) !== []) {
            return collect();
        }
        $query = Topic::query()->useWritePdo()->where('site_key', $siteKey)->whereNotNull('public_revision_id')->with(['publicRevision.articles'])->orderBy('display_order')->orderByDesc('first_published_at')->orderByDesc('id');
        if (! empty($filters['article_id'])) {
            $query->whereHas('publicRevision.articles', fn ($q) => $q->where('article_id', (int) $filters['article_id']));
        }
        $items = collect();
        foreach ($query->lazy(100)->chunk(100) as $batch) {
            app(TopicViewBuilder::class)->withReadBatch($siteKey, $batch, function () use ($batch, $items, $filters, $limit): void {
                foreach ($batch as $topic) {
                    $view = $this->topics->publicView($topic);
                    if ($view === null) {
                        continue;
                    }
                    $search = trim((string) ($filters['search'] ?? ''));
                    if ($search !== '' && ! str_contains(mb_strtolower($view['title'].' '.$view['intro']), mb_strtolower($search))) {
                        continue;
                    }
                    $tag = (string) ($filters['tag'] ?? '');
                    if ($tag !== '' && ! in_array($tag, $view['tags'], true)) {
                        continue;
                    }
                    if (! empty($filters['article_id']) && ! in_array((int) $filters['article_id'], array_column($view['articles'], 'article_id'), true)) {
                        continue;
                    }
                    $card = array_intersect_key($view, array_flip(['id', 'site_key', 'slug', 'path', 'url', 'base_title', 'title', 'template_key', 'tags', 'article_count', 'modified_at', 'first_published_at', 'timezone']));
                    $card['intro'] = mb_substr($view['intro'], 0, 400);
                    $card['summary'] = ['one_sentence' => mb_substr($view['summary']['one_sentence'], 0, 400)];
                    $items->push($card);
                    if ($limit !== null && $items->count() >= $limit) {
                        return;
                    }
                }
            });
            if ($limit !== null && $items->count() >= $limit) {
                break;
            }
        }

        return $this->requestViews[$cacheKey] = $items;
    }

    public function paginate(string $siteKey, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min(50, $perPage));
        $items = $this->all($siteKey, $filters);

        return new TopicPaginator($items->forPage(max(1, $page), $perPage)->values(), $items->count(), $perPage, max(1, $page), ['path' => request()->url(), 'query' => request()->query()]);
    }

    public function home(string $siteKey): Collection
    {
        $settings = $this->settings->get($siteKey);

        return $settings['home_enabled'] && app(TopicTemplateCatalog::class)->homeEnabled($siteKey) ? $this->all($siteKey, [], (int) $settings['home_limit'])->values() : collect();
    }

    public function related(string $siteKey, int $articleId): Collection
    {
        return $this->all($siteKey, ['article_id' => $articleId], 3)->values();
    }

    /** @param array<string,mixed> $view @return array<string,mixed> */
    public function detailSchema(array $view, string $siteName, string $homeUrl): array
    {
        return ['@context' => 'https://schema.org', '@graph' => [
            array_filter(['@type' => 'CollectionPage', '@id' => $view['url'].'#page', 'url' => $view['url'], 'name' => $view['title'], 'description' => $this->description($view), 'datePublished' => $view['first_published_at'], 'dateModified' => $view['modified_at'], 'lastReviewed' => $view['freshness_snapshot_json']['last_verified_at'] ?? null, 'isPartOf' => ['@type' => 'WebSite', '@id' => $homeUrl.'#website', 'name' => $siteName, 'url' => $homeUrl], 'mainEntity' => ['@id' => $view['url'].'#articles']], fn ($v): bool => $v !== null && $v !== ''),
            ['@type' => 'ItemList', '@id' => $view['url'].'#articles', 'numberOfItems' => $view['article_count'], 'itemListElement' => array_map(fn (array $a, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => ['@type' => 'Article', 'name' => $a['title'], 'url' => $a['url']]], $view['articles'], array_keys($view['articles']))],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $homeUrl], ['@type' => 'ListItem', 'position' => 2, 'name' => $this->settings->get($view['site_key'])['channel_name'], 'item' => rtrim($homeUrl, '/').'/topics'], ['@type' => 'ListItem', 'position' => 3, 'name' => $view['title'], 'item' => $view['url']]]],
        ]];
    }

    public function description(array $view): string
    {
        return mb_substr(strip_tags((string) ($view['summary']['one_sentence'] ?: $view['intro'] ?: $view['title'])), 0, 180);
    }
}
