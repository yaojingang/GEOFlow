<?php

namespace App\Services\LinkPool;

use App\Exceptions\ApiException;
use App\Http\Controllers\Site\ArticleController;
use App\Models\Article;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Site\LinkPoolSiteService;
use App\Support\Site\CurrentSite;
use App\Support\Site\LinkPoolThemeValidationContext;
use App\Support\Site\SiteThemeViewResolver;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\View;

final class LinkPoolThemeValidator
{
    public function __construct(
        private readonly ThemeRevisionStorage $storage,
        private readonly LinkPoolSiteService $sites,
        private readonly CurrentSite $currentSite,
        private readonly ThemeRevisionContext $revisionContext,
        private readonly LinkPoolThemeValidationContext $validation,
    ) {}

    /** @return array{article_html_sha256:string,empty_html_sha256:string,article_url:?string} */
    public function validate(ThemeRevision $revision, string $siteKey): array
    {
        $files = $this->storage->contents($revision);
        $articlePath = 'resources/views/theme/'.$revision->theme_id.'/article.blade.php';
        $articleSource = $files[$articlePath] ?? $files['resources/views/site/article.blade.php'] ?? null;
        if (! is_string($articleSource)) {
            throw new ApiException('theme_article_missing', '文章模板不存在', 422);
        }
        Blade::compileString($articleSource);

        $wasResolved = $this->currentSite->isResolved();
        $oldType = $wasResolved ? $this->currentSite->type() : null;
        $oldHostname = $wasResolved ? $this->currentSite->hostname() : null;
        $oldProfile = $this->currentSite->profile();
        $oldRequest = app('request');
        try {
            $profile = $siteKey === 'primary' ? null : $this->sites->profile($siteKey);
            if ($siteKey !== 'primary' && $profile === null) {
                throw new ApiException('site_not_found', '托管站不存在', 404);
            }
            $profile ? $this->currentSite->setHosted($profile) : $this->currentSite->setPrimary((string) parse_url(config('geoflow.site_url', config('app.url')), PHP_URL_HOST));
            $article = $this->sites->query($siteKey)->with(['category', 'author'])->latest('articles.id')->first();
            $articleUrl = $article ? $this->sites->url($siteKey, $article) : null;
            $block = ['heading' => '推荐内容', 'items' => [[
                'url' => 'https://example.test/theme-adaptation-check',
                'title' => '验证推荐链接 <script>', 'target_site_key' => $siteKey,
            ]]];
            $filled = $this->render($revision, $article, $articleUrl, $block);
            $empty = $this->render($revision, $article, $articleUrl, null);
            if (! str_contains($filled, 'href="https://example.test/theme-adaptation-check"')
                || ! str_contains($filled, '验证推荐链接')
                || ! str_contains($filled, '&lt;script&gt;')
                || ! str_contains($filled, 'data-link-pool')
                || ! str_contains($filled, '/assets/css/link-pool.css')
                || ! str_contains($filled, '/js/link-pool.js')
                || str_contains($empty, 'data-link-pool')) {
                throw new ApiException('theme_render_contract_failed', '主题文章页未通过推荐区与空池验证', 422);
            }

            return ['article_html_sha256' => hash('sha256', $filled), 'empty_html_sha256' => hash('sha256', $empty), 'article_url' => $articleUrl];
        } finally {
            app()->instance('request', $oldRequest);
            if ($oldType === CurrentSite::TYPE_HOSTED && $oldProfile !== null) {
                $this->currentSite->setHosted($oldProfile);
            } elseif ($oldType === CurrentSite::TYPE_PRIMARY) {
                $this->currentSite->setPrimary((string) $oldHostname);
            } else {
                $this->currentSite->clear();
            }
        }
    }

    private function render(ThemeRevision $revision, ?Article $article, ?string $articleUrl, ?array $block): string
    {
        return $this->revisionContext->preview($revision, fn (): string => $this->validation->run($block, function () use ($article, $articleUrl, $block): string {
            $request = Request::create($articleUrl ?? $this->currentSite->baseUrl().'/__theme-preview', 'GET');
            app()->instance('request', $request);
            if ($article !== null) {
                $view = app(ArticleController::class)->show($request);
                if (! $view instanceof View) {
                    throw new ApiException('theme_render_contract_failed', '文章预览未返回主题页面', 422);
                }

                return $view->render();
            }
            $sample = new Article;
            $sample->title = '主题预览文章';
            $sample->slug = 'theme-preview';
            $sample->content = '主题预览正文';
            $sample->created_at = now();

            return SiteThemeViewResolver::first('article', [
                'article' => $sample, 'activeNav' => 'article', 'contentHtml' => '<p>主题预览正文</p>',
                'excerptPlain' => '主题预览正文', 'tags' => [], 'relatedArticles' => collect(),
                'recommendationBlocks' => $block === null ? [] : [$block], 'stickyAd' => null,
                'siteTitle' => 'GEOFlow', 'siteDescription' => '', 'siteKeywords' => '',
                'pageTitle' => '主题预览文章', 'pageDescription' => '', 'pageKeywords' => '',
                'pageOgType' => 'article', 'canonicalUrl' => $request->fullUrl(),
            ])->render();
        }));
    }
}
