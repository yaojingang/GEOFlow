<?php

namespace App\Services\Api;

use App\Http\ApiAuthContext;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\CategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Models\ThemeRevision;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\SystemUpdater\RecoveryState;
use App\Support\Site\SiteThemePreviewContext;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

final class ThemeWorkspacePreview
{
    public const HEADERS = [
        'Content-Security-Policy' => "default-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; sandbox allow-scripts",
        'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(private ThemeWorkspaceAuthorization $authorization, private ThemeRevisionStorage $storage, private RecoveryState $recovery) {}

    public function links(ApiAuthContext $auth, string $workspaceId): array
    {
        $workspace = $this->authorization->workspace($auth, $workspaceId, 'themes:read');
        $this->authorization->assertCode($auth, $workspace);
        $article = app(SiteScopedArticleQuery::class)->query()->with('category')->latest('id')->first();
        $paths = [
            'home' => '', 'search' => '', 'category' => $article?->category ? 'category/'.$article->category->slug : null,
            'article' => $article ? ltrim(app(ArticlePermalinkService::class)->path($article), '/') : null, 'about' => 'about', 'archive-index' => 'archive',
            'archive-month' => $article ? 'archive/'.($article->published_at ?? $article->created_at)->format('Y/m') : null,
            'empty-state' => '',
        ];
        $pages = [];
        foreach ($paths as $page => $path) {
            $query = match ($page) {
                'search' => ['search' => 'GEOFlow'], 'empty-state' => ['search' => 'geoflow-empty-'.bin2hex(random_bytes(12))], default => []
            };
            $pages[$page] = $path === null ? ['available' => false, 'reason' => 'published_content_required'] : [
                'available' => true, 'path' => $path, 'query' => $query,
                'url' => URL::temporarySignedRoute('api.v1.theme-preview', now()->addMinutes(15), [
                    'workspace' => $workspace->id, 'revision' => $workspace->revision_id, 'sitePath' => $path,
                    'token_id' => $auth->token['id'], 'recovery_epoch' => $this->recovery->assertHttpReady()['epoch'] ?? null, ...$query,
                ]),
            ];
        }

        return ['workspace_id' => $workspace->id, 'revision_id' => $workspace->revision_id, 'pages' => $pages, 'expires_in' => 900];
    }

    public function fromSignedRequest(Request $request, string $workspace, string $revision): ApiAuthContext
    {
        abort_unless($request->hasValidSignature(), 403);
        $state = $this->recovery->assertHttpReady();
        abort_if($state !== null && $request->query('recovery_epoch') !== $state['epoch'], 403);
        $token = PersonalAccessToken::query()->find($request->integer('token_id'));
        abort_unless($token, 403);
        $auth = new ApiAuthContext(['id' => $token->id, 'scopes' => $token->abilities], (int) $token->tokenable_id);
        $draft = $this->authorization->workspace($auth, $workspace, 'themes:read');
        $this->authorization->assertCode($auth, $draft);
        abort_unless($draft->revision_id === $revision, 409);

        return $auth;
    }

    public function render(ApiAuthContext $auth, string $workspaceId, string $sitePath = '', array $query = [], ?string $expectedRevision = null): string
    {
        $workspace = $this->authorization->workspace($auth, $workspaceId, 'themes:read');
        $this->authorization->assertCode($auth, $workspace);
        abort_if($expectedRevision !== null && $workspace->revision_id !== $expectedRevision, 409);
        $revision = ThemeRevision::query()->findOrFail($workspace->revision_id);
        $this->storage->contents($revision);
        $pageType = $this->pageType($sitePath);
        abort_if($pageType === null, 404);
        if ($pageType === 'article') {
            $sitePath = ltrim(app(ArticlePermalinkService::class)->resolve('/'.$sitePath)->canonicalPath, '/');
        }
        $request = Request::create(rtrim(config('app.url'), '/').'/'.$sitePath, 'GET', array_intersect_key($query, array_flip(['search', 'page'])));
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $frameBase = URL::route('api.v1.theme-preview', ['workspace' => $workspace->id, 'revision' => $revision->id]);

        $previousSession = Session::getFacadeRoot();
        $session = new Store('geoflow_theme_preview', new ArraySessionHandler(15));
        $session->start();
        $request->setLaravelSession($session);
        Session::swap($session);
        try {
            return app(ThemeRevisionContext::class)->preview($revision, fn (): string => app(SiteThemePreviewContext::class)->run($workspace->theme_id, $frameBase, function () use ($request, $sitePath, $pageType, $revision, $auth, $workspace, $frameBase): string {
                $page = match ($pageType) {
                    'home' => app(HomeController::class)->index($request),
                    'about' => app(AboutController::class)->index(),
                    'archive' => app(ArchiveController::class)->index(),
                    'category' => app(CategoryController::class)->show($request, rawurldecode(substr($sitePath, 9))),
                    'article' => app(ArticleController::class)->show($request),
                    default => app(ArchiveController::class)->month(...array_slice(explode('/', $sitePath), 1)),
                };
                abort_unless($page instanceof View, 422);
                $html = $page->render();
                $html = preg_replace_callback('~\b(src|href|poster)=("|\')(.*?)\2~is', function (array $match) use ($auth, $workspace, $revision): string {
                    $url = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $signed = $this->assetReference($url, '', $auth, $workspace->id, $revision);

                    return $signed === null ? $match[0] : $match[1].'='.$match[2].htmlspecialchars($signed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').$match[2];
                }, $html) ?? $html;

                return $this->signNavigation($html, $auth, $workspace->id, $revision->id, $frameBase, $sitePath);
            }, $request));
        } finally {
            Session::swap($previousSession);
        }
    }

    public function assetUrl(ApiAuthContext $auth, string $workspace, string $revision, string $path): string
    {
        return URL::temporarySignedRoute('api.v1.theme-preview-asset', now()->addMinutes(15), [
            'workspace' => $workspace, 'revision' => $revision, 'assetPath' => $path, 'token_id' => $auth->token['id'], 'recovery_epoch' => $this->recovery->assertHttpReady()['epoch'] ?? null,
        ]);
    }

    public function stylesheet(string $css, string $path, ApiAuthContext $auth, string $workspace, ThemeRevision $revision): string
    {
        $css = preg_replace_callback('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s)]*))\s*\)~i', function (array $match) use ($path, $auth, $workspace, $revision): string {
            $url = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
            $signed = $this->assetReference($url, $path, $auth, $workspace, $revision);

            return $signed === null ? $match[0] : 'url("'.$signed.'")';
        }, $css) ?? $css;

        return preg_replace_callback('~(@import\s+)("|\')([^"\']+)\2~i', function (array $match) use ($path, $auth, $workspace, $revision): string {
            $signed = $this->assetReference($match[3], $path, $auth, $workspace, $revision);

            return $signed === null ? $match[0] : $match[1].'"'.$signed.'"';
        }, $css) ?? $css;
    }

    private function assetReference(string $url, string $sourcePath, ApiAuthContext $auth, string $workspace, ThemeRevision $revision): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['host']) && strtolower($parts['host']) !== strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))) {
            return null;
        }
        $path = $parts['path'] ?? '';
        $prefix = (string) parse_url(asset('themes/'.$revision->theme_id.'/'), PHP_URL_PATH);
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        } elseif (str_starts_with($path, '/themes/'.$revision->theme_id.'/')) {
            $path = substr($path, strlen('/themes/'.$revision->theme_id.'/'));
        } elseif ($sourcePath !== '' && $path !== '' && ! str_starts_with($path, '/')) {
            $path = dirname($sourcePath).'/'.$path;
        } else {
            return null;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $path = implode('/', $segments);
        if (! isset($revision->files['public/themes/'.$revision->theme_id.'/'.$path])) {
            return null;
        }
        $signed = $this->assetUrl($auth, $workspace, $revision->id, $path);

        return isset($parts['fragment']) ? $signed.'#'.$parts['fragment'] : $signed;
    }

    private function pageType(string $path): ?string
    {
        if ($path === '') {
            return 'home';
        }
        if (in_array($path, ['about', 'archive'], true)) {
            return $path;
        }
        if (preg_match('~\Aarchive/[0-9]{4}/[0-9]{2}\z~D', $path)) {
            return 'archive-month';
        }
        if (preg_match('~\Acategory/[^/\\\\]+\z~D', $path)) {
            return 'category';
        }

        return app(ArticlePermalinkService::class)->resolve('/'.$path) !== null ? 'article' : null;
    }

    private function signNavigation(string $html, ApiAuthContext $auth, string $workspace, string $revision, string $frameBase, string $currentPath): string
    {
        $prefix = rtrim((string) parse_url($frameBase, PHP_URL_PATH), '/');

        return preg_replace_callback('~\b(href|action)=("|\')(.*?)\2~is', function (array $match) use ($auth, $workspace, $revision, $prefix, $currentPath): string {
            $url = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = parse_url($url);
            if (! is_array($parts) || isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || isset($parts['host']) && strtolower($parts['host']) !== strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))) {
                return $match[0];
            }
            $path = $parts['path'] ?? '';
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $path = ltrim(substr($path, strlen($prefix)), '/');
            } elseif ($path === '' && isset($parts['query'])) {
                $path = $currentPath;
            } elseif (str_starts_with($path, '/')) {
                $basePath = rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');
                $path = $basePath !== '' && str_starts_with($path, $basePath.'/') ? substr($path, strlen($basePath)) : $path;
                $path = ltrim($path, '/');
            } elseif ($path !== '') {
                $parent = dirname($currentPath);
                $path = ($parent === '.' ? '' : $parent.'/').$path;
            } else {
                return $match[0];
            }
            if ($this->pageType($path) === null) {
                return $match[0];
            }
            parse_str($parts['query'] ?? '', $query);
            $query = array_intersect_key($query, array_flip(['search', 'page']));
            $signed = URL::temporarySignedRoute('api.v1.theme-preview', now()->addMinutes(15), [
                'workspace' => $workspace, 'revision' => $revision, 'sitePath' => $path, 'token_id' => $auth->token['id'], 'recovery_epoch' => $this->recovery->assertHttpReady()['epoch'] ?? null, ...$query,
            ]);
            if (isset($parts['fragment'])) {
                $signed .= '#'.$parts['fragment'];
            }

            return $match[1].'='.$match[2].htmlspecialchars($signed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').$match[2];
        }, $html) ?? $html;
    }
}
