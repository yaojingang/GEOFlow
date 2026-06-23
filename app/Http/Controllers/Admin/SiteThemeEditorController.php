<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\Admin\SiteThemeEditorService;
use App\Support\AdminWeb;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\ArticleStickyAdPicker;
use App\Support\Site\ArticleTextAdPicker;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SiteThemeEditorController extends Controller
{
    public function __construct(private readonly SiteThemeEditorService $editor) {}

    public function edit(string $themeId, string $page): View
    {
        $this->authorizeThemeEditor();
        $source = $this->editor->source($themeId, $page);

        return view('admin.site-theme-editor.edit', [
            'adminSiteName' => AdminWeb::siteName(),
            'activeMenu' => 'site-settings',
            'pageTitle' => __('admin.theme_editor.page_title'),
            'themeId' => $themeId,
            'page' => $page,
            'source' => $source,
            'pageOptions' => $this->pageOptions(),
        ]);
    }

    public function preview(string $themeId, string $page): Response
    {
        $this->authorizeThemeEditor();

        try {
            $source = $this->editor->source($themeId, $page);
            $html = Blade::render($source['blade'], $this->previewData($page));
            $html = $this->injectDraftCss($html, $source['css']);

            return response($html);
        } catch (Throwable $e) {
            report($e);

            return response($this->previewErrorHtml($e));
        }
    }

    public function draft(Request $request, string $themeId, string $page): JsonResponse
    {
        $this->authorizeThemeEditor();
        $payload = $request->validate([
            'blade' => ['required', 'string'],
            'css' => ['nullable', 'string'],
        ]);

        $result = $this->editor->saveDraft(
            $themeId,
            $page,
            (string) $payload['blade'],
            (string) ($payload['css'] ?? ''),
            auth('admin')->id()
        );

        return response()->json([
            'ok' => true,
            'message' => __('admin.theme_editor.draft_saved'),
            'updated_at' => $result['updated_at'],
        ]);
    }

    public function publish(Request $request, string $themeId, string $page): JsonResponse
    {
        $this->authorizeThemeEditor();
        $payload = $request->validate([
            'blade' => ['required', 'string'],
            'css' => ['nullable', 'string'],
        ]);

        $result = $this->editor->publish(
            $themeId,
            $page,
            (string) $payload['blade'],
            (string) ($payload['css'] ?? ''),
            auth('admin')->id()
        );

        return response()->json([
            'ok' => true,
            'message' => __('admin.theme_editor.publish_success'),
            'backup_dir' => $result['backup_dir'],
            'updated_at' => $result['updated_at'],
        ]);
    }

    public function discard(string $themeId, string $page): JsonResponse
    {
        $this->authorizeThemeEditor();
        $this->editor->discardDraft($themeId, $page);

        return response()->json([
            'ok' => true,
            'message' => __('admin.theme_editor.discard_success'),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function pageOptions(): array
    {
        return [
            'home' => __('admin.theme_editor.page_home'),
            'category' => __('admin.theme_editor.page_category'),
            'article' => __('admin.theme_editor.page_article'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function previewData(string $page): array
    {
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));
        $category = $this->sampleCategory();
        $articles = $this->sampleArticles($category);
        $paginator = $this->paginator($articles, $page === 'category' ? route('site.category', $category->slug) : route('site.home'));
        $cardSummaries = [];

        foreach ($articles as $article) {
            $cardSummaries[(int) $article->id] = ArticleHtmlPresenter::cardSummary($article, 120);
        }

        $base = [
            'siteTitle' => $siteTitle,
            'siteName' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'siteSubtitle' => (string) ($map['site_subtitle'] ?? ''),
            'activeNav' => $page,
            'canonicalUrl' => route('site.home'),
            'category' => $category,
            'categoryMissing' => false,
            'categoryId' => (int) $category->id,
            'articles' => $paginator,
            'featuredArticles' => $articles->take(4),
            'hotArticles' => $articles->take(6),
            'relatedArticles' => $articles->take(6),
            'cardSummaries' => $cardSummaries,
            'homepageCarouselSlides' => [],
            'search' => '',
            'viewTitle' => __('site.home_latest'),
            'pageTitle' => $siteTitle,
            'pageDescription' => $siteDescription,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'perPage' => 12,
        ];

        if ($page === 'category') {
            return array_merge($base, [
                'activeNav' => 'category',
                'articles' => $paginator,
                'pageTitle' => $category->name.' - '.$siteTitle,
                'pageDescription' => (string) $category->description,
                'pageKeywords' => $siteKeywords,
                'pageOgType' => 'website',
                'canonicalUrl' => route('site.category', $category->slug),
            ]);
        }

        if ($page === 'article') {
            $article = $articles->first();
            $body = ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title);
            $contentHtml = ArticleTextAdPicker::injectIntoContentHtml(ArticleHtmlPresenter::markdownToHtml($body));
            $tags = $this->keywordTags((string) $article->keywords);

            return array_merge($base, [
                'activeNav' => 'article',
                'article' => $article,
                'contentHtml' => $contentHtml,
                'excerptPlain' => ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->excerpt, (string) $article->title),
                'tags' => $tags,
                'pageTitle' => (string) $article->title,
                'pageDescription' => (string) $article->excerpt,
                'pageKeywords' => implode(',', $tags),
                'pageOgType' => 'article',
                'stickyAd' => ArticleStickyAdPicker::firstEnabled(),
                'canonicalUrl' => route('site.article', $article->slug),
            ]);
        }

        return array_merge($base, [
            'activeNav' => 'home',
            'pageTitle' => $siteTitle,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'canonicalUrl' => route('site.home'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function keywordTags(string $keywords): array
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            return ['GEO', 'AI 搜索', '内容结构'];
        }

        $parts = preg_split('/[,，、\n]+/u', $keywords) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $tag = trim((string) $part);
            if ($tag !== '' && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @return Collection<int,Article>
     */
    private function sampleArticles(Category $fallbackCategory): Collection
    {
        if (Schema::hasTable('articles')) {
            $articles = Article::query()
                ->with(['category', 'author'])
                ->published()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get();

            if ($articles->isNotEmpty()) {
                return $articles;
            }
        }

        $author = new Author([
            'name' => 'GEOFlow 编辑部',
            'bio' => '负责示例内容与模板预览。',
        ]);
        $author->id = 1;

        $titles = [
            '如何重新安装 macOS',
            '让文章更容易被 AI 搜索引用',
            '用关键词库规划一周内容',
            'GEO 与 SEO 是竞争还是互补？企业应如何布局双引擎策略',
            '为什么 AI 搜索更偏爱某些品牌？',
            '小型企业也能做 GEO 吗？低预算下的品牌 AI 可见度提升方案',
        ];

        return collect($titles)->map(function (string $title, int $index) use ($fallbackCategory, $author): Article {
            $date = Carbon::now()->subDays($index);
            $article = new Article([
                'title' => $title,
                'slug' => 'theme-preview-'.($index + 1),
                'excerpt' => '这是一条用于模板预览的示例摘要，帮助检查首页、分类页和文章详情页的真实排版效果。',
                'content' => "# {$title}\n\n这是用于实时预览的示例正文。它包含段落、列表和引用，方便检查长文阅读体验。\n\n- 结构清晰\n- 证据充分\n- 适合 AI 搜索引用\n\n> 模板编辑只影响前台展示层，不改变文章数据。",
                'status' => 'published',
                'review_status' => 'approved',
                'keywords' => 'GEO,AI 搜索,内容结构',
                'view_count' => 120 + $index,
                'published_at' => $date,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
            $article->id = $index + 1;
            $article->setRelation('category', $fallbackCategory);
            $article->setRelation('author', $author);

            return $article;
        });
    }

    private function sampleCategory(): Category
    {
        if (Schema::hasTable('categories')) {
            $category = Category::query()->orderBy('sort_order')->orderBy('id')->first();
            if ($category instanceof Category) {
                return $category;
            }
        }

        $category = new Category([
            'name' => 'Mac 支持',
            'slug' => 'mac',
            'description' => '围绕 macOS、备份、迁移、连接和性能维护的帮助文章。',
            'sort_order' => 10,
        ]);
        $category->id = 1;

        return $category;
    }

    /**
     * @param  Collection<int,Article>  $articles
     */
    private function paginator(Collection $articles, string $path): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $articles,
            $articles->count(),
            max(1, $articles->count()),
            1,
            ['path' => $path]
        );
    }

    private function injectDraftCss(string $html, string $css): string
    {
        $safeCss = str_ireplace('</style', '<\/style', $css);
        $style = '<style id="geoflow-theme-editor-draft-css">'.$safeCss.'</style>';
        if (Str::contains($html, '</head>')) {
            return str_replace('</head>', $style.'</head>', $html);
        }

        return $style.$html;
    }

    private function previewErrorHtml(Throwable $e): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff;color:#111827;padding:28px}.box{border:1px solid #fecaca;background:#fff1f2;border-radius:12px;padding:18px}h1{font-size:18px;margin:0 0 10px;color:#991b1b}pre{white-space:pre-wrap;font-size:13px;line-height:1.6}</style></head><body><div class="box"><h1>'.e(__('admin.theme_editor.preview_error_title')).'</h1><pre>'.e($e->getMessage()).'</pre></div></body></html>';
    }

    private function authorizeThemeEditor(): void
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);
    }
}
