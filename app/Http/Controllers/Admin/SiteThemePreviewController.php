<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\CategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\TopicController;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Topics\TopicReadModel;
use App\Support\Site\CurrentSite;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemeCatalog;
use App\Support\Site\SiteThemePreviewContext;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class SiteThemePreviewController extends Controller
{
    public function __construct(
        private readonly InstalledSiteThemeRepository $themes,
        private readonly SiteThemePreviewContext $preview,
        private readonly SiteScopedArticleQuery $articles,
        private readonly CurrentSite $currentSite,
    ) {}

    public function show(string $themeId, string $page = 'home'): View
    {
        abort_if($this->currentSite->isHosted(), 404);
        $theme = $this->themes->find($themeId);
        abort_unless($theme !== null, 404);

        return $this->showTheme($theme, $page, 'admin.site-settings.theme-packages.preview');
    }

    public function libraryShow(Request $request, string $themeId, string $page = 'home'): View
    {
        abort_unless($request->user('admin')?->canManageProtectedWorkflows(), 403);
        abort_if($this->currentSite->isHosted(), 404);
        $theme = app(SiteThemeCatalog::class)->libraryTheme($themeId);
        abort_unless($theme !== null, 404);

        return $this->showTheme($theme, $page, 'admin.site-settings.themes.preview');
    }

    private function showTheme(array $theme, string $page, string $previewRoute): View
    {
        $article = $this->articles->query()->with('category')->latest('id')->first();
        $topic = app(TopicReadModel::class)->all('primary')->first();
        $paths = [
            'home' => '',
            'category' => $article?->category ? 'category/'.rawurlencode($article->category->slug) : null,
            'article' => $article ? 'article/'.rawurlencode($article->slug) : null,
            'topic-list' => 'topics',
            'topic-show' => $topic ? 'topics/'.rawurlencode($topic['slug']) : null,
            'topic-empty' => 'topics?search='.rawurlencode('预览空列表 '.Str::uuid()),
            'about' => 'about',
            'archive-index' => 'archive',
            'archive-month' => $article ? 'archive/'.($article->published_at ?? $article->created_at)->format('Y/m') : null,
        ];
        abort_unless(array_key_exists($page, $paths), 404);
        $theme['preview_id'] = $theme['id'] === '' ? SiteThemeCatalog::DEFAULT_PREVIEW_ID : $theme['id'];
        $frameBase = route($previewRoute.'.frame', ['themeId' => $theme['preview_id']]);

        return view('admin.site-theme-packages.preview', [
            'pageTitle' => __('admin.theme_packages.preview.title'),
            'activeMenu' => 'site_settings',
            'theme' => $theme,
            'previewRoute' => $previewRoute,
            'pages' => $paths,
            'selectedPage' => $page,
            'frameBase' => $frameBase,
            'frameUrl' => $paths[$page] === null ? null : rtrim($frameBase, '/').'/'.$paths[$page],
        ]);
    }

    public function frame(Request $request, string $themeId, string $sitePath = ''): Response|RedirectResponse
    {
        abort_if($this->currentSite->isHosted(), 404);
        abort_unless($this->themes->find($themeId) !== null, 404);

        return $this->renderFrame($request, $themeId, $sitePath, 'admin.site-settings.theme-packages.preview.frame');
    }

    public function libraryFrame(Request $request, string $themeId, string $sitePath = ''): Response|RedirectResponse
    {
        abort_unless($request->user('admin')?->canManageProtectedWorkflows(), 403);
        abort_if($this->currentSite->isHosted(), 404);
        $theme = app(SiteThemeCatalog::class)->libraryTheme($themeId);
        abort_unless($theme !== null, 404);

        return $this->renderFrame($request, $theme['id'], $sitePath, 'admin.site-settings.themes.preview.frame');
    }

    private function renderFrame(Request $request, string $themeId, string $sitePath, string $frameRoute): Response|RedirectResponse
    {
        $sitePath = trim($sitePath, '/');
        abort_unless(preg_match('~\A(?:topics(?:/page/[1-9][0-9]*|/[^/\\\\]+)?|about|archive(?:/[0-9]{4}/[0-9]{2})?|(?:category|article)/[^/\\\\]+)?\z~D', $sitePath) === 1, 404);
        $frameBase = route($frameRoute, ['themeId' => $themeId === '' ? SiteThemeCatalog::DEFAULT_PREVIEW_ID : $themeId]);
        $headers = [
            'Content-Security-Policy' => "default-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; sandbox allow-scripts allow-popups",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];

        $siteRequest = Request::create($request->getSchemeAndHttpHost().'/'.$sitePath, 'GET', $request->query->all(), $request->cookies->all(), [], $request->server->all());
        $siteRequest->setLaravelSession($request->session());
        $siteRequest->setUserResolver($request->getUserResolver());
        $siteRoute = Route::getRoutes()->match($siteRequest);
        $siteRequest->setRouteResolver(fn () => $siteRoute);

        try {
            $render = fn () => $this->preview->run($themeId, $frameBase, function () use ($siteRequest, $sitePath, $frameBase): string|RedirectResponse {
                $page = match (true) {
                    $sitePath === '' => app(HomeController::class)->index($siteRequest),
                    $sitePath === 'topics' => app(TopicController::class)->index($siteRequest),
                    str_starts_with($sitePath, 'topics/page/') => app(TopicController::class)->index($siteRequest, (int) substr($sitePath, 12)),
                    str_starts_with($sitePath, 'topics/') => app(TopicController::class)->show(substr($sitePath, 7)),
                    $sitePath === 'about' => app(AboutController::class)->index(),
                    $sitePath === 'archive' => app(ArchiveController::class)->index(),
                    str_starts_with($sitePath, 'category/') => app(CategoryController::class)->show($siteRequest, substr($sitePath, 9)),
                    str_starts_with($sitePath, 'article/') => app(ArticleController::class)->show($siteRequest),
                    default => app(ArchiveController::class)->month(...array_slice(explode('/', $sitePath), 1)),
                };
                if ($page instanceof RedirectResponse) {
                    abort_unless(str_starts_with($page->getTargetUrl(), rtrim($frameBase, '/').'/'), 404);

                    return $page->setStatusCode(302);
                }
                foreach (($page instanceof View ? $page->getData() : []) as $value) {
                    if ($value instanceof AbstractPaginator) {
                        $value->withPath(rtrim($frameBase, '/').'/'.$sitePath);
                    }
                }
                $html = $page instanceof View ? $page->render() : (string) $page->getContent();
                $bridge = view('admin.site-theme-packages.preview-bridge', ['frameBase' => $frameBase])->render();

                return stripos($html, '</body>') !== false
                    ? preg_replace_callback('~</body>~i', fn (): string => $bridge.'</body>', $html, 1)
                    : $html.$bridge;
            }, $siteRequest);

            $context = app(ThemeRevisionContext::class);
            $snapshot = $context->snapshot();
            $html = ($snapshot['revision_id'] ?? null) !== null && $snapshot['theme_id'] !== $themeId
                ? $context->preview(null, $render)
                : $render();

            if ($html instanceof RedirectResponse) {
                return $html->withHeaders($headers);
            }

            if (($snapshot['revision_id'] ?? null) !== null && $snapshot['theme_id'] === $themeId) {
                $html = str_replace('/themes/'.$themeId.'/', '/theme-assets/'.$snapshot['revision_id'].'/', $html);
                $headers['X-GEOFlow-Theme-Revision'] = $snapshot['revision_id'];
            }

            return response($html, 200, $headers);
        } catch (NotFoundHttpException) {
            return response()->view('admin.site-theme-packages.preview-error', ['message' => __('admin.theme_packages.preview.no_content')], 404, $headers);
        } catch (Throwable $exception) {
            report($exception);

            return response()->view('admin.site-theme-packages.preview-error', ['message' => __('admin.theme_packages.preview.failed')], 422, $headers);
        }
    }
}
