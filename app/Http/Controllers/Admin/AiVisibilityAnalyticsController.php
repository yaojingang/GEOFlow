<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorReportService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
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
                    ? AiVisibilityRun::query()->whereNotNull('keyword')->where('keyword', '!=', '')->distinct()->orderBy('keyword')->pluck('keyword')
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
                ? AiVisibilityCompetitor::query()->orderBy('id')->get()
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

        Artisan::queue('geoflow:ai-visibility:collect', ['keywords' => $keywords->all()]);

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
            ['aliases' => $aliases, 'is_active' => true],
        );

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.saved'));
    }

    public function destroyCompetitor(Request $request, int $competitor): RedirectResponse
    {
        AiVisibilityCompetitor::query()->whereKey($competitor)->delete();

        return back()->with('message', __('admin.analytics.ai_visibility.competitors.deleted'));
    }

    /**
     * @return Collection<int, array{id: int, name: string, keywords: list<array{id: int, keyword: string}>}>
     */
    private function keywordLibraries(): Collection
    {
        if (! Schema::hasTable('keyword_libraries') || ! Schema::hasTable('keywords')) {
            return collect();
        }

        return KeywordLibrary::query()
            ->with('keywords:id,library_id,keyword')
            ->orderBy('id')
            ->get()
            ->map(static fn (KeywordLibrary $library): array => [
                'id' => $library->id,
                'name' => $library->name,
                'keywords' => $library->keywords
                    ->map(static fn (Keyword $keyword): array => [
                        'id' => $keyword->id,
                        'keyword' => (string) $keyword->keyword,
                    ])
                    ->filter(static fn (array $item): bool => $item['keyword'] !== '')
                    ->values()
                    ->all(),
            ])
            ->filter(static fn (array $library): bool => $library['keywords'] !== [])
            ->values();
    }
}
