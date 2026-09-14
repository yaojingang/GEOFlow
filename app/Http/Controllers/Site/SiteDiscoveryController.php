<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\HostedSiteProfile;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SitemapManifest;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Site\SiteUrlGenerator;
use App\Services\Site\UrlChangeService;
use App\Support\Site\CurrentSite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SiteDiscoveryController extends Controller
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly SiteUrlGenerator $urls,
        private readonly ArticlePermalinkService $articlePermalinks,
    ) {}

    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        if (! $this->indexingAllowed()) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            $lines[] = 'Sitemap: '.$this->urls->sitemap();
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        if ($this->currentSite->isHosted()) {
            $articleCount = $this->indexingAllowed() ? $this->siteArticles->query()->count() : 0;

            return $this->sitemapIndex($articleCount);
        }

        $urls = [];
        if ($this->indexingAllowed()) {
            $articleCount = $this->siteArticles->query()->count();
            if ($articleCount + 1 > $this->primarySitemapInlineLimit()) {
                return $this->sitemapIndex($articleCount);
            }

            $urls[] = ['loc' => $this->urls->home(), 'lastmod' => null];
            $articles = $this->withPermalinkRelations($this->siteArticles->query())
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit($this->primarySitemapInlineLimit() - 1)
                ->get(['id', 'slug', 'category_id', 'created_at', 'updated_at']);
            $changedAt = $this->urlChangedAt();
            foreach ($articles as $article) {
                $urls[] = [
                    'loc' => $this->urls->article($article),
                    'lastmod' => $this->lastmod($article->updated_at, $changedAt),
                ];
            }
        }

        return $this->urlSetResponse($urls);
    }

    public function sitemapShard(int $page): Response|StreamedResponse
    {
        abort_unless($page > 0, 404);

        $articleCount = $this->indexingAllowed() ? $this->siteArticles->query()->count() : 0;
        $site = $this->manifestSite();
        $manifests = app(SitemapManifest::class);
        $manifest = $manifests->current($site);
        abort_unless(
            $this->currentSite->isHosted()
                || $manifest !== null
                || $articleCount + 1 > $this->primarySitemapInlineLimit(),
            404,
        );
        $pageCount = $manifest['pages'] ?? $this->sitemapPageCount($articleCount);
        abort_if($page > $pageCount, 404);

        $urls = [];
        if ($this->indexingAllowed()) {
            if ($page === 1) {
                $urls[] = ['loc' => $this->urls->home(), 'lastmod' => null];
            }
            $urlLimit = $this->sitemapUrlLimit();
            if ($manifest === null && $articleCount <= 500) {
                $manifests->buildStep($site);
                $manifest = $manifests->current($site);
            }
            if ($manifest === null) {
                return response('', 503, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
            }
            abort_unless(isset($manifest['boundaries'][$page - 1]), 404);
            $limit = $urlLimit - ($page === 1 ? 1 : 0);
            $articles = $this->withPermalinkRelations($this->siteArticles->query())
                ->orderBy('articles.id')
                ->where('articles.id', '>', $manifest['boundaries'][$page - 1])
                ->select(['id', 'slug', 'category_id', 'created_at', 'updated_at']);
            if (isset($manifest['boundaries'][$page])) {
                $articles->where('articles.id', '<=', $manifest['boundaries'][$page]);
            }
            $changedAt = $this->urlChangedAt();

            return response()->stream(function () use ($articles, $urls, $limit, $changedAt): void {
                echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
                foreach ($urls as $url) {
                    echo $this->urlXml($url);
                }
                foreach ($articles->lazyById(500, 'articles.id', 'id')->take($limit) as $article) {
                    echo $this->urlXml(['loc' => $this->urls->article($article), 'lastmod' => $this->lastmod($article->updated_at, $changedAt)]);
                }
                echo '</urlset>'."\n";
            }, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-cache, private']);
        }

        return $this->urlSetResponse($urls);
    }

    private function sitemapIndex(int $articleCount): Response
    {
        $pageCount = $this->sitemapPageCount($articleCount);
        if ($articleCount > 500) {
            $manifest = app(SitemapManifest::class)->current($this->manifestSite());
            if ($manifest !== null) {
                $pageCount = $manifest['pages'];
            }
        }
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        if ($this->indexingAllowed()) {
            for ($page = 1; $page <= $pageCount; $page++) {
                $body .= '  <sitemap><loc>'.htmlspecialchars(
                    $this->urls->sitemapShard($page),
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8'
                ).'</loc></sitemap>'."\n";
            }
        }
        $body .= '</sitemapindex>'."\n";

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /** @param list<array{loc:string,lastmod:?string}> $urls */
    private function urlSetResponse(array $urls): Response
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $body .= $this->urlXml($url);
        }
        $body .= '</urlset>'."\n";

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    private function sitemapPageCount(int $articleCount): int
    {
        return max(1, (int) ceil(($articleCount + 1) / $this->sitemapUrlLimit()));
    }

    private function primarySitemapInlineLimit(): int
    {
        return min(5000, $this->sitemapUrlLimit());
    }

    private function withPermalinkRelations(Builder $query): Builder
    {
        if ($this->articlePermalinks->currentPatternUses('category')) {
            $query->with('category:id,slug');
        }

        return $query;
    }

    private function sitemapUrlLimit(): int
    {
        return min(50000, max(2, (int) config('geoflow.hosted_sites.sitemap_url_limit', 50000)));
    }

    private function indexingAllowed(): bool
    {
        return ! $this->currentSite->isHosted()
            || $this->currentSite->profile()?->indexing_status === HostedSiteProfile::INDEXING_INDEX;
    }

    private function manifestSite(): array
    {
        return app(UrlChangeService::class)->sites($this->currentSite->isHosted() ? 'hosted' : 'primary', $this->currentSite->profile()?->distribution_channel_id)[0];
    }

    private function urlXml(array $url): string
    {
        return '  <url><loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
            .($url['lastmod'] !== null ? '<lastmod>'.htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</lastmod>' : '').'</url>'."\n";
    }

    private function urlChangedAt(): ?CarbonImmutable
    {
        if (! Schema::hasTable('url_change_scope_states')) {
            return null;
        }
        $scope = $this->currentSite->isHosted() ? 'hosted:'.$this->currentSite->profile()?->distribution_channel_id : 'primary';
        $value = DB::table('url_change_scope_states')->where('scope_key', $scope)->value('url_changed_at');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    private function lastmod(?CarbonInterface $updated, ?CarbonImmutable $urlChanged): ?string
    {
        return ($urlChanged && (! $updated || $urlChanged->gt($updated)) ? $urlChanged : $updated)?->toAtomString();
    }
}
