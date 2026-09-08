<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\CategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Services\Site\SiteScopedArticleQuery;
use App\Support\Site\CurrentSite;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemePreviewContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Route;
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

        $article = $this->articles->query()->with('category')->latest('id')->first();
        $paths = [
            'home' => '',
            'category' => $article?->category ? 'category/'.rawurlencode($article->category->slug) : null,
            'article' => $article ? 'article/'.rawurlencode($article->slug) : null,
            'about' => 'about',
            'archive-index' => 'archive',
            'archive-month' => $article ? 'archive/'.($article->published_at ?? $article->created_at)->format('Y/m') : null,
        ];
        abort_unless(array_key_exists($page, $paths), 404);
        $frameBase = route('admin.site-settings.theme-packages.preview.frame', ['themeId' => $themeId]);

        return view('admin.site-theme-packages.preview', [
            'pageTitle' => __('admin.theme_packages.preview.title'),
            'activeMenu' => 'site_settings',
            'theme' => $theme,
            'pages' => $paths,
            'selectedPage' => $page,
            'frameBase' => $frameBase,
            'frameUrl' => $paths[$page] === null ? null : rtrim($frameBase, '/').'/'.$paths[$page],
        ]);
    }

    public function frame(Request $request, string $themeId, string $sitePath = ''): Response
    {
        abort_if($this->currentSite->isHosted(), 404);
        abort_unless($this->themes->find($themeId) !== null, 404);
        $sitePath = trim($sitePath, '/');
        abort_unless(preg_match('~\A(?:about|archive(?:/[0-9]{4}/[0-9]{2})?|(?:category|article)/[^/\\\\]+)?\z~D', $sitePath) === 1, 404);
        $frameBase = route('admin.site-settings.theme-packages.preview.frame', ['themeId' => $themeId]);
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
            $html = $this->preview->run($themeId, $frameBase, function () use ($siteRequest, $sitePath, $frameBase): string {
                $page = match (true) {
                    $sitePath === '' => app(HomeController::class)->index($siteRequest),
                    $sitePath === 'about' => app(AboutController::class)->index(),
                    $sitePath === 'archive' => app(ArchiveController::class)->index(),
                    str_starts_with($sitePath, 'category/') => app(CategoryController::class)->show(substr($sitePath, 9)),
                    str_starts_with($sitePath, 'article/') => app(ArticleController::class)->show(substr($sitePath, 8)),
                    default => app(ArchiveController::class)->month(...array_slice(explode('/', $sitePath), 1)),
                };
                foreach ($page->getData() as $value) {
                    if ($value instanceof AbstractPaginator) {
                        $value->withPath(rtrim($frameBase, '/').'/'.$sitePath);
                    }
                }
                $html = $page->render();
                $bridge = view('admin.site-theme-packages.preview-bridge', ['frameBase' => $frameBase])->render();

                return stripos($html, '</body>') !== false
                    ? preg_replace_callback('~</body>~i', fn (): string => $bridge.'</body>', $html, 1)
                    : $html.$bridge;
            }, $siteRequest);

            return response($html, 200, $headers);
        } catch (NotFoundHttpException) {
            return response()->view('admin.site-theme-packages.preview-error', ['message' => __('admin.theme_packages.preview.no_content')], 404, $headers);
        } catch (Throwable $exception) {
            report($exception);

            return response()->view('admin.site-theme-packages.preview-error', ['message' => __('admin.theme_packages.preview.failed')], 422, $headers);
        }
    }
}
