<?php

namespace App\Http\Controllers\Admin;

use App\Data\Ai\SystemAiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\CollectAiVisibilityKeywordJob;
use App\Jobs\DetectAiVisibilityCompetitorsJob;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\AiVisibilityTopic;
use App\Models\AiVisibilityTopicKeyword;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use App\Services\Admin\Analytics\AiVisibilityCompetitorReportService;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Services\GeoFlow\AiVisibility\AiVisibilitySourceData;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiVisibilityAnalyticsController extends Controller
{
    public function __construct(
        private readonly AiVisibilityAnalyticsService $analytics,
        private readonly AiVisibilityCompetitorReportService $competitorReport,
        private readonly AiVisibilityConfigurationResolver $configuration,
        private readonly AiVisibilityService $visibility,
    ) {}

    public function refreshTerms(Request $request): RedirectResponse
    {
        $filter = AiVisibilityAnalyticsFilter::fromRequest($request->query());
        $this->analytics->overview($filter, refreshTermCleanup: true);

        return back()->with('message', '词云清洗已处理。结果将按当前候选词与模型配置缓存；模型不可用时保留启发式结果。');
    }

    public function search(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:100'], 'mode' => ['nullable', 'in:global,custom'],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'], 'need_summary' => ['nullable', 'boolean'],
            'query_rewrite' => ['nullable', 'boolean'], 'time_range' => ['nullable', 'string', 'max:40'],
            'industry' => ['nullable', 'in:finance,game,health,gov'], 'auth_info_level' => ['nullable', 'in:0,1'],
            'summary_length' => ['nullable', 'integer', 'min:100', 'max:4000'], 'image_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'competitor_analysis' => ['nullable', 'boolean'],
            'sites' => ['nullable', 'string', 'max:1000'], 'block_hosts' => ['nullable', 'string', 'max:500'],
            'topic_id' => ['nullable', 'integer', 'exists:ai_visibility_topics,id'],
        ]);
        $provider = $this->configuration->searchProvider(SystemAiIdentity::forVisibilityCollection());
        if ($provider === null) {
            throw ValidationException::withMessages(['query' => '豆包搜索供应商尚未配置。']);
        }
        $options = array_replace($payload, [
            'sites' => preg_split('/[|,\r\n]+/', (string) ($payload['sites'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            'block_hosts' => preg_split('/[|,\r\n]+/', (string) ($payload['block_hosts'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
        ]);
        $topic = ($payload['topic_id'] ?? null) ? AiVisibilityTopic::find((int) $payload['topic_id']) : null;
        $run = $this->visibility->runDoubaoSearchCustom(
            $provider,
            (string) $payload['query'],
            $options,
            $topic,
        );
        if ((bool) ($payload['competitor_analysis'] ?? false)) {
            $model = $this->configuration->deepSeekModel(SystemAiIdentity::forVisibilityCollection());
            if ($model !== null) {
                $run->load('sources');
                $sources = $run->sources->map(static fn ($source): AiVisibilitySourceData => new AiVisibilitySourceData(
                    sourceType: (string) $source->source_type, citationKey: $source->citation_key, title: $source->title,
                    url: $source->url, domain: $source->domain, siteName: $source->site_name, snippet: $source->snippet,
                    summary: $source->summary, contentExcerpt: $source->content_excerpt, publishedAt: $source->published_at,
                    rank: $source->rank, rankScore: $source->rank_score, authorityLevel: $source->authority_level,
                    metadata: is_array($source->metadata_json) ? $source->metadata_json : [],
                ))->values()->all();
                try {
                    $this->visibility->runDeepSeekAnalysis(SystemAiIdentity::forVisibilityCollection(), $model, (string) $payload['query'], '识别搜索结果提及的全部竞品，输出严格 JSON：{"competitors":[{"name":"品牌","aliases":[],"confidence":0.8,"evidence":[{"url":"逐字复制输入信源URL","reason":"证据"}]}]}。无可靠信源URL时evidence为空数组。', $sources, topic: $topic);
                } catch (\Throwable) {
                    // Search results remain available even if optional analysis is unavailable.
                }
            }
        }

        return redirect()->route('admin.analytics.ai-visibility', ['ai_run' => $run->id])->with('message', '搜索完成');
    }

    public function assignTopic(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'run_id' => ['required', 'integer', 'exists:ai_visibility_runs,id'],
            'topic_id' => ['required', 'integer', 'exists:ai_visibility_topics,id'],
        ]);
        $run = AiVisibilityRun::query()->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)->findOrFail((int) $payload['run_id']);
        $run->update(['ai_visibility_topic_id' => (int) $payload['topic_id']]);
        if ($run->keyword_hash !== null && $run->keyword_hash !== AiVisibilityKeywordNormalizer::hash('')) {
            AiVisibilityTopicKeyword::query()->updateOrCreate(
                ['keyword_hash' => $run->keyword_hash],
                ['ai_visibility_topic_id' => (int) $payload['topic_id'], 'keyword' => $run->keyword],
            );
        }

        return redirect()->route('admin.analytics.ai-visibility', ['ai_run' => $run->id])->with('message', '已归类');
    }

    private function selectedRun(Request $request): ?AiVisibilityRun
    {
        $id = (int) $request->query('ai_run', 0);
        if ($id <= 0) {
            return null;
        }

        return AiVisibilityRun::query()->with('sources')->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)->whereKey($id)->first();
    }

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
                'visibilityTopics' => Schema::hasTable('ai_visibility_topics') ? AiVisibilityTopic::query()->orderBy('name')->get(['id', 'name']) : collect(),
                'providers' => [
                    AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
                    AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
                    AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
                ],
            ],
            'selectedRun' => $this->selectedRun($request),
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
     * @return Collection<int, array{id: int, name: string, keywords: list<array{id: int, keyword: string, recently_sampled: bool}>}>
     */
    private function keywordLibraries(): Collection
    {
        if (! Schema::hasTable('keyword_libraries') || ! Schema::hasTable('keywords')) {
            return collect();
        }

        $keywords = Keyword::query()->where('keyword', '!=', '')->orderBy('id')->limit(1000)
            ->get(['id', 'library_id', 'keyword'])->groupBy('library_id');
        $sampled = $this->recentlySampledKeywords();

        return KeywordLibrary::query()->whereIn('id', $keywords->keys())->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (KeywordLibrary $library): array => [
                'id' => $library->id,
                'name' => $library->name,
                'keywords' => $keywords->get($library->id, collect())
                    ->map(static fn (Keyword $keyword): array => [
                        'id' => $keyword->id,
                        'keyword' => (string) $keyword->keyword,
                        'recently_sampled' => isset($sampled[AiVisibilityKeywordNormalizer::normalize((string) $keyword->keyword)]),
                    ])
                    ->values()->all(),
            ])->values();
    }

    /**
     * 最近 7 天已经发起过采样的关键词，用于在采集面板标记并跳过重复采集。
     *
     * 关键词按与专题归类一致的归一化规则比较，因此大小写、空白和全角差异
     * 不会让同一个关键词被重复采集。
     *
     * @return array<string, true>
     */
    private function recentlySampledKeywords(): array
    {
        if (! Schema::hasTable('ai_visibility_runs')) {
            return [];
        }

        $since = now()->subDays(7);
        $runs = AiVisibilityRun::query()
            ->whereIn('provider_type', AiVisibilityRun::SAMPLE_PROVIDERS)
            ->whereNotNull('keyword')
            ->where('keyword', '!=', '')
            ->whereRaw('COALESCE(completed_at, started_at, created_at) >= ?', [$since])
            ->get(['keyword']);

        $sampled = [];
        foreach ($runs as $run) {
            $key = AiVisibilityKeywordNormalizer::normalize((string) $run->keyword);
            if ($key !== '') {
                $sampled[$key] = true;
            }
        }

        return $sampled;
    }
}
