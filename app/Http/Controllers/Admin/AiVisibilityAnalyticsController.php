<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CollectAiVisibilityKeywordJob;
use App\Jobs\DetectAiVisibilityCompetitorsJob;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorReportService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AiVisibilityAnalyticsController extends Controller
{
    public function __construct(
        private readonly AiVisibilityAnalyticsService $analytics,
        private readonly AiVisibilityCompetitorReportService $competitorReport,
    ) {}

    public function __invoke(Request $request): View
    {
        $filter = AiVisibilityAnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.ai-visibility', [
            'pageTitle' => __('admin.analytics.pages.ai_visibility.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'ai-visibility',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => [
                'keywords' => Schema::hasTable('ai_visibility_runs')
                    ? AiVisibilityRun::query()->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)->whereNotNull('keyword')->where('keyword', '!=', '')->distinct()->orderBy('keyword')->limit(1000)->pluck('keyword')
                    : collect(),
                'providers' => [
                    AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
                    AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
                    AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
                ],
            ],
            'aiVisibilityOverview' => $this->analytics->overview($filter),
            'keywordLibraries' => $this->keywordLibraries(),
            'competitorReport' => Schema::hasTable('ai_visibility_competitors')
                ? $this->competitorReport->stats(30)
                : null,
            'competitors' => Schema::hasTable('ai_visibility_competitors')
                ? AiVisibilityCompetitor::query()->select('id', 'name', 'aliases', 'is_active', 'source')->lazyById(200)->collect()
                : collect(),
            'topCitedUrls' => Schema::hasTable('ai_visibility_sources')
                ? $this->topCitedUrls(30, 10)
                : collect(),
        ]);
    }

    /**
     * 从关键词库勾选关键词，派发后台队列批量采集。
     */
    public function collect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'keyword_ids' => ['required', 'array', 'min:1', 'max:50'],
            'keyword_ids.*' => ['integer'],
        ]);

        $keywords = collect(Arr::wrap($data['keyword_ids']))
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => preg_match('/^\d+$/', $id) === 1)
            ->values();

        $keywords = Keyword::query()
            ->whereIn('id', $keywords)
            ->pluck('keyword')
            ->map(static fn ($keyword): string => trim((string) $keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '' && mb_strlen($keyword) <= 100)
            ->unique()
            ->values();

        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.analytics.ai_visibility.collect.empty'));
        }

        foreach ($keywords as $keyword) {
            CollectAiVisibilityKeywordJob::dispatch($keyword);
        }

        return back()->with(
            'message',
            __('admin.analytics.ai_visibility.collect.queued', ['count' => $keywords->count()]),
        );
    }

    public function storeCompetitor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'aliases' => ['nullable', 'string', 'max:500'],
        ]);

        $aliases = collect((array) preg_split('/[,，;；\n]+/u', (string) ($data['aliases'] ?? '')))
            ->map(static fn ($alias): string => trim((string) $alias))
            ->filter(static fn (string $alias): bool => $alias !== '')
            ->unique()
            ->values()
            ->all();

        AiVisibilityCompetitor::query()->updateOrCreate(
            ['name' => Str::limit(trim($data['name']), 120, '')],
            ['aliases' => $aliases, 'is_active' => true, 'source' => 'manual'],
        );

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.saved'));
    }

    public function destroyCompetitor(Request $request, int $competitor): RedirectResponse
    {
        AiVisibilityCompetitor::query()->whereKey($competitor)->delete();

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.deleted'));
    }

    /**
     * 派发后台队列,用 AI 从最近的采样回答中自动识别竞品品牌。
     */
    public function detectCompetitors(AiVisibilityCompetitorDetectionService $detection): RedirectResponse
    {
        foreach ($detection->pendingRunIds(12) as $runId) {
            DetectAiVisibilityCompetitorsJob::dispatch($runId);
        }

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.detect_queued'));
    }

    /**
     * 近 N 天被 AI 回答引用最多的具体网址(可点击跳转)。
     *
     * @return Collection<int, array{url: string, title: string, domain: string, citations: int}>
     */
    private function topCitedUrls(int $days, int $limit): Collection
    {
        return AiVisibilitySource::query()
            ->whereHas('run', static fn ($query) => $query
                ->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays($days)))
            ->where(static fn ($query) => $query->where('url', 'like', 'https://%')->orWhere('url', 'like', 'http://%'))
            ->selectRaw('url, MIN(title) as title, COUNT(*) as citations')
            ->groupBy('url')->orderByDesc('citations')->orderBy('url')->limit($limit)
            ->get()->filter(static fn ($source): bool => filter_var($source->url, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($source->url, PHP_URL_SCHEME)), ['http', 'https'], true))
            ->map(static fn ($source): array => [
                'url' => (string) $source->url,
                'title' => (string) ($source->title ?: $source->url),
                'domain' => (string) parse_url($source->url, PHP_URL_HOST),
                'citations' => (int) $source->citations,
            ])->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, keywords: list<array{id: int, keyword: string}>}>
     */
    private function keywordLibraries(): Collection
    {
        if (! Schema::hasTable('keyword_libraries') || ! Schema::hasTable('keywords')) {
            return collect();
        }

        $keywords = Keyword::query()->where('keyword', '!=', '')->orderBy('id')->limit(1000)
            ->get(['id', 'library_id', 'keyword'])->groupBy('library_id');

        return KeywordLibrary::query()->whereIn('id', $keywords->keys())->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (KeywordLibrary $library): array => [
                'id' => $library->id,
                'name' => $library->name,
                'keywords' => $keywords->get($library->id, collect())
                    ->map(static fn (Keyword $keyword): array => ['id' => $keyword->id, 'keyword' => (string) $keyword->keyword])
                    ->values()->all(),
            ])->values();
    }
}
