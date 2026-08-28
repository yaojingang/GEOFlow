<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\HostedSiteProfile;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\CurrentSite;
use Illuminate\Http\Response;

final class SiteDiscoveryController extends Controller
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly SiteUrlGenerator $urls,
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
            return $this->hostedSitemapIndex();
        }

        $urls = [];
        if ($this->indexingAllowed()) {
            $urls[] = ['loc' => $this->urls->home(), 'lastmod' => null];
            $articles = $this->siteArticles->query()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(5000)
                ->get(['id', 'slug', 'updated_at']);
            foreach ($articles as $article) {
                $urls[] = [
                    'loc' => $this->urls->article($article),
                    'lastmod' => $article->updated_at?->toAtomString(),
                ];
            }
        }

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $body .= '  <url><loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if ($url['lastmod'] !== null) {
                $body .= '<lastmod>'.htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</lastmod>';
            }
            $body .= '</url>'."\n";
        }
        $body .= '</urlset>'."\n";

        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapShard(int $page): Response
    {
        abort_unless($this->currentSite->isHosted() && $page > 0, 404);

        $articleCount = $this->indexingAllowed() ? $this->siteArticles->query()->count() : 0;
        $pageCount = $this->hostedSitemapPageCount($articleCount);
        abort_if($page > $pageCount, 404);

        $urls = [];
        if ($this->indexingAllowed()) {
            if ($page === 1) {
                $urls[] = ['loc' => $this->urls->home(), 'lastmod' => null];
            }
            $urlLimit = $this->sitemapUrlLimit();
            $offset = max(0, (($page - 1) * $urlLimit) - 1);
            $limit = $urlLimit - ($page === 1 ? 1 : 0);
            $articles = $this->siteArticles->query()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($limit)
                ->get(['id', 'slug', 'updated_at']);
            foreach ($articles as $article) {
                $urls[] = [
                    'loc' => $this->urls->article($article),
                    'lastmod' => $article->updated_at?->toAtomString(),
                ];
            }
        }

        return $this->urlSetResponse($urls);
    }

    private function hostedSitemapIndex(): Response
    {
        $articleCount = $this->indexingAllowed() ? $this->siteArticles->query()->count() : 0;
        $pageCount = $this->hostedSitemapPageCount($articleCount);
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
            $body .= '  <url><loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if ($url['lastmod'] !== null) {
                $body .= '<lastmod>'.htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</lastmod>';
            }
            $body .= '</url>'."\n";
        }
        $body .= '</urlset>'."\n";

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    private function hostedSitemapPageCount(int $articleCount): int
    {
        return max(1, (int) ceil(($articleCount + 1) / $this->sitemapUrlLimit()));
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
}
