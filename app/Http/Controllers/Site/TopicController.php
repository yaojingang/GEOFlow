<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SiteUrlGenerator;
use App\Services\Topics\TopicNamespaceGuard;
use App\Services\Topics\TopicPathService;
use App\Services\Topics\TopicReadModel;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicSiteSettings;
use App\Services\Topics\TopicTemplateCatalog;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemePreviewContext;
use App\Support\Site\SiteThemeViewResolver;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class TopicController extends Controller
{
    public function __construct(private readonly TopicReadModel $read, private readonly TopicService $topics, private readonly TopicSiteSettings $settings, private readonly SiteUrlGenerator $urls) {}

    public function index(Request $request, ?int $page = null): Response|View|RedirectResponse
    {
        $key = $this->settings->currentKey();
        $request ??= request();
        if (app(TopicNamespaceGuard::class)->conflicts($key) !== []) {
            return app(ArticleController::class)->show($request);
        }
        $request->validate(['search' => ['nullable', 'string', 'max:200'], 'tag' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        abort_unless($this->settings->get($key)['enabled'], 404);
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 200);
        $tag = mb_substr(trim((string) $request->query('tag', '')), 0, 100);
        $requested = $page ?? max(1, (int) $request->query('page', 1));
        if (($page === 1) || ($page === null && $request->has('page'))) {
            return redirect($this->urls->topics(array_filter(['page' => $requested, 'search' => $search, 'tag' => $tag])), 301);
        }
        $page = $requested;
        $topics = $this->read->paginate($key, compact('search', 'tag'), $page);
        abort_if($page > $topics->lastPage() && $page > 1, 404);
        abort_if($topics->total() === 0 && ! $this->settings->get($key)['topics_public_opened_at'] && ! app(SiteThemePreviewContext::class)->isActive(), 404);
        $data = $this->common();
        $data += ['topics' => $topics, 'search' => $search, 'tag' => $tag, 'topicTags' => $this->read->all($key)->flatMap(fn ($t) => $t['tags'])->unique()->take(30), 'pageTitle' => $data['topicChannelName'].($page > 1 ? ' · 第 '.$page.' 页' : '').' | '.$data['siteTitle'], 'pageDescription' => '围绕主题整理本站文章，提供有来源的摘要与阅读指南。', 'canonicalUrl' => $this->urls->topics(array_filter(['page' => $page > 1 ? $page : null, 'search' => $search, 'tag' => $tag])), 'activeNav' => 'topics', 'pageOgType' => 'website'];
        if ($search !== '' || $tag !== '' || $topics->total() === 0) {
            $data['pageNoindex'] = true;
        }

        return response(SiteThemeViewResolver::first('topics.index', $data))->header('Cache-Control', 'no-cache, private');
    }

    public function show(string $slug, ?Request $request = null): Response|View|RedirectResponse
    {
        $key = $this->settings->currentKey();
        $request ??= request();
        if (app(TopicNamespaceGuard::class)->conflicts($key) !== []) {
            return app(ArticleController::class)->show($request);
        }
        abort_unless($this->settings->get($key)['enabled'], 404);
        $topic = app(TopicPathService::class)->resolve($key, $slug);
        abort_if($topic === null, 404);
        $view = $this->topics->publicView($topic);
        abort_if($view === null, 404);
        if ($slug !== $topic->slug) {
            return redirect()->to($view['url'], 301)->header('Cache-Control', 'no-cache, private');
        }

        return response(SiteThemeViewResolver::first('topics.show', $this->detailData($view)))->header('Cache-Control', 'no-cache, private');
    }

    public function legacy(Request $request): View|RedirectResponse
    {
        abort_if(app(TopicNamespaceGuard::class)->conflicts($this->settings->currentKey()) === [], 404);

        return app(ArticleController::class)->show($request);
    }

    public function previewMarkup(array $view): string
    {
        return SiteThemeViewResolver::first('topics.show', $this->detailData($view) + ['pageNoindex' => true])->render();
    }

    public function detailData(array $view): array
    {
        $data = $this->common();

        return $data + ['topic' => $view, 'topicTemplateView' => app(TopicTemplateCatalog::class)->viewForSite($view['site_key'], $view['template_key']), 'topicArticles' => $view['articles'], 'topicSummary' => $view['summary'], 'topicScore' => $view['score'], 'pageTitle' => trim($view['seo']['title'] ?? '') !== '' ? $view['seo']['title'] : $view['title'].' | '.$data['siteTitle'], 'pageDescription' => trim($view['seo']['description'] ?? '') !== '' ? $view['seo']['description'] : $this->read->description($view), 'pageKeywords' => implode(',', $view['tags']), 'canonicalUrl' => $view['url'], 'activeNav' => 'topics', 'pageOgType' => 'website', 'topicStructuredData' => $this->read->detailSchema($view, $data['siteTitle'], $this->urls->home())];
    }

    private function common(): array
    {
        app(ThemeRevisionContext::class)->views();
        $theme = SiteThemeViewResolver::activeThemeId();
        $layout = $theme && \Illuminate\Support\Facades\View::exists('theme.'.$theme.'.layout') ? 'theme.'.$theme.'.layout' : 'site.layout';
        $section = 'content';
        $bytes = file_get_contents(\Illuminate\Support\Facades\View::getFinder()->find($layout));
        if (str_contains($bytes, "@yield('theme_content')")) {
            $section = 'theme_content';
        }
        $map = SiteSettingsBag::all();

        return ['topicChannelName' => $this->settings->get($this->settings->currentKey())['channel_name'], 'topicLayout' => $layout, 'topicContentSection' => $section, 'siteTitle' => (string) ($map['site_name'] ?? config('geoflow.site_name')), 'siteDescription' => (string) ($map['site_description'] ?? ''), 'siteKeywords' => (string) ($map['site_keywords'] ?? '')];
    }
}
