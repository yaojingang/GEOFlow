<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\ArticleStickyAdPicker;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 前台文章详情（对齐旧版 article.php：浏览计数、Markdown 正文、相关文章）。
 */
class ArticleController extends Controller
{
    public function show(string $slug): View
    {
        $article = Article::query()
            ->published()
            ->where('slug', $slug)
            ->with(['category', 'author'])
            ->first();

        if (! $article instanceof Article) {
            throw new NotFoundHttpException(__('site.article_not_found'));
        }

        $article->increment('view_count');
        $article->refresh();

        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));

        $rawContent = (string) $article->content;
        $body = ArticleHtmlPresenter::stripLeadingTitleHeading($rawContent, (string) $article->title);
        $excerpt = trim((string) $article->excerpt);
        if ($excerpt !== '') {
            $excerpt = ArticleHtmlPresenter::stripLeadingTitleHeading($excerpt, (string) $article->title);
        }

        $contentHtml = ArticleHtmlPresenter::markdownToHtml($body);

        $tags = $this->keywordTags((string) $article->keywords);

        $related = Article::query()
            ->published()
            ->where('category_id', $article->category_id)
            ->whereKeyNot($article->id)
            ->inRandomOrder()
            ->limit(6)
            ->get(['id', 'title', 'slug']);

        $pageTitle = $article->title.' - '.$siteTitle;
        $pageDescription = $excerpt !== '' ? $excerpt : ArticleHtmlPresenter::cardSummary($article, 160);

        $stickyAd = ArticleStickyAdPicker::firstEnabled();

        return SiteThemeViewResolver::first('article', [
            'activeNav' => 'article',
            'article' => $article,
            'contentHtml' => $contentHtml,
            'excerptPlain' => $excerpt,
            'tags' => $tags,
            'relatedArticles' => $related,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => '',
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageDescription,
            'stickyAd' => $stickyAd,
            'canonicalUrl' => route('site.article', $article->slug),
        ]);
    }

    /**
     * @return list<string>
     */
    private function keywordTags(string $keywords): array
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            return [];
        }

        $parts = preg_split('/[,，、\n]+/u', $keywords) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $t = trim((string) $part);
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return array_slice($out, 0, 12);
    }
}
