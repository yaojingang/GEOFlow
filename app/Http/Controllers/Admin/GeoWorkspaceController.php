<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeoBatchCrawlCitationSourcesJob;
use App\Jobs\GeoBatchScoreCitationSourcesJob;
use App\Jobs\GeoPostPublishRetestJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoAiSearchRun;
use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoKeyword;
use App\Models\GeoKeywordOpportunity;
use App\Models\GeoReport;
use App\Models\GeoTask;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Services\Geo\GeoArticleAuditService;
use App\Services\Geo\GeoArticleDraftGenerator;
use App\Services\Geo\GeoArticlePublisher;
use App\Services\Geo\GeoDiagnosisRunner;
use App\Services\Geo\GeoKeywordCombinationService;
use App\Services\Geo\GeoKeywordDiscoveryService;
use App\Services\Geo\GeoPostPublishRetestRunner;
use App\Services\Geo\GeoReferenceBriefBuilder;
use App\Services\Geo\GeoReferenceContentQualityScorer;
use App\Services\Geo\GeoReferenceDraftGenerator;
use App\Services\Geo\GeoReferencePageCrawler;
use App\Services\Geo\GeoSearchBatchRunner;
use App\Support\AdminWeb;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class GeoWorkspaceController extends Controller
{
    /**
     * GEO MVP 工作台：品牌资料、关键词和诊断任务的第一版闭环入口。
     */
    public function index(): View
    {
        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        $platforms = $this->ensureDefaultPlatforms();
        $realAiModels = $this->loadActiveChatAiModels();

        return view('admin.geo.workspace', [
            'pageTitle' => 'GEO 工作台',
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'brandProfile' => $brandProfile,
            'keywords' => GeoKeyword::query()
                ->where('organization_id', $organization->id)
                ->latest()
                ->limit(30)
                ->get(),
            'opportunities' => GeoKeywordOpportunity::query()
                ->where('organization_id', $organization->id)
                ->orderByDesc('opportunity_score')
                ->latest()
                ->limit(20)
                ->get(),
            'platforms' => $platforms,
            'realAiModels' => $realAiModels,
            'tasks' => GeoTask::query()
                ->where('organization_id', $organization->id)
                ->with('report')
                ->withCount('questions')
                ->latest()
                ->limit(10)
                ->get(),
            'searchRuns' => GeoAiSearchRun::query()
                ->where('organization_id', $organization->id)
                ->withCount(['questions', 'answers'])
                ->latest()
                ->limit(10)
                ->get(),
            'citationSources' => GeoCitationSource::query()
                ->where('organization_id', $organization->id)
                ->with('latestPageSnapshot.latestScore')
                ->orderByDesc('last_seen_at')
                ->latest()
                ->limit(12)
                ->get(),
            'trendMetrics' => $this->trendMetrics($organization),
            'pipelineMetrics' => $this->pipelineMetrics($organization),
        ]);
    }

    /**
     * 保存当前企业的品牌知识库。
     */
    public function saveBrandProfile(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'organization_name' => ['required', 'string', 'max:120'],
            'brand_name' => ['required', 'string', 'max:160'],
            'aliases_text' => ['nullable', 'string'],
            'products' => ['nullable', 'string'],
            'advantages' => ['nullable', 'string'],
            'cases' => ['nullable', 'string'],
            'pain_points' => ['nullable', 'string'],
            'service_area' => ['nullable', 'string', 'max:255'],
            'extra_facts' => ['nullable', 'string'],
            'short_name' => ['nullable', 'string', 'max:120'],
            'writing_directions' => ['nullable', 'string'],
            'copy_types' => ['nullable', 'string'],
            'product_features' => ['nullable', 'string'],
            'brand_story' => ['nullable', 'string'],
            'trust_proofs' => ['nullable', 'string'],
            'promotion_regions' => ['nullable', 'string'],
            'forbidden_claims' => ['nullable', 'string'],
        ], [
            'organization_name.required' => '请填写企业名称',
            'brand_name.required' => '请填写品牌名称',
        ]);

        $admin = $this->currentAdmin();

        DB::transaction(function () use ($admin, $payload): void {
            $organization = $this->resolveOrganization($admin);
            $organization->update([
                'name' => trim((string) $payload['organization_name']),
                'status' => 'active',
            ]);

            BrandProfile::query()->updateOrCreate(
                ['organization_id' => $organization->id],
                [
                    'brand_name' => trim((string) $payload['brand_name']),
                    'aliases' => $this->parseAliases((string) ($payload['aliases_text'] ?? '')),
                    'products' => trim((string) ($payload['products'] ?? '')),
                    'advantages' => trim((string) ($payload['advantages'] ?? '')),
                    'cases' => trim((string) ($payload['cases'] ?? '')),
                    'pain_points' => trim((string) ($payload['pain_points'] ?? '')),
                    'service_area' => trim((string) ($payload['service_area'] ?? '')),
                    'extra_facts' => trim((string) ($payload['extra_facts'] ?? '')),
                    'extended_profile' => $this->extendedProfileFromPayload($payload),
                    'status' => 'active',
                ]
            );
        });

        return redirect()->route('admin.geo.workspace')->with('message', '品牌知识库已保存');
    }

    /**
     * 添加一个 GEO 关键词或问题词。
     */
    public function storeKeyword(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['industry', 'brand', 'competitor', 'question'])],
            'intent' => ['nullable', 'string', 'max:80'],
        ], [
            'keyword.required' => '请填写关键词',
            'type.in' => '关键词类型不正确',
        ]);

        $organization = $this->resolveOrganization($this->currentAdmin());
        $keyword = trim((string) $payload['keyword']);
        if ($keyword === '') {
            return back()->withErrors('请填写关键词');
        }

        GeoKeyword::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'type' => (string) $payload['type'],
                'keyword' => $keyword,
            ],
            [
                'intent' => trim((string) ($payload['intent'] ?? '')),
                'status' => 'active',
            ]
        );

        return redirect()->route('admin.geo.workspace')->with('message', '关键词已加入 GEO 关键词库');
    }

    /**
     * 基于企业资料批量生成 GEO 关键词机会。
     */
    public function generateOpportunities(Request $request, GeoKeywordDiscoveryService $discoveryService): RedirectResponse
    {
        $payload = $request->validate([
            'limit' => ['nullable', 'integer', 'min:3', 'max:50'],
        ]);

        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        if (! $brandProfile instanceof BrandProfile) {
            return back()->withErrors('请先保存品牌知识库，再生成关键词机会');
        }

        $opportunities = $discoveryService->generateFromBrandProfile(
            $organization,
            $brandProfile,
            $admin,
            (int) ($payload['limit'] ?? 12)
        );

        return redirect()
            ->route('admin.geo.workspace')
            ->with('message', '已生成 '.$opportunities->count().' 个关键词机会，可创建 AI 搜索批次');
    }

    /**
     * 根据 ABCDEF 词组手工拓展 GEO 关键词机会。
     */
    public function expandOpportunities(Request $request, GeoKeywordCombinationService $combinationService): RedirectResponse
    {
        $payload = $request->validate([
            'area_prefixes' => ['nullable', 'string', 'max:5000'],
            'modifiers' => ['nullable', 'string', 'max:5000'],
            'core_terms' => ['required', 'string', 'max:5000'],
            'entity_terms' => ['required', 'string', 'max:5000'],
            'recommend_terms' => ['nullable', 'string', 'max:5000'],
            'question_terms' => ['nullable', 'string', 'max:5000'],
            'combination_patterns' => ['required', 'array', 'min:1'],
            'combination_patterns.*' => ['required', Rule::in(GeoKeywordCombinationService::allowedPatterns())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ], [
            'core_terms.required' => '请填写 C 核心产品词',
            'entity_terms.required' => '请填写 D 实体类型词',
            'combination_patterns.required' => '请选择至少一种组合规则',
        ]);

        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        if (! $brandProfile instanceof BrandProfile) {
            return back()->withErrors('请先保存品牌知识库，再进行手工拓词');
        }

        $opportunities = $combinationService->generateFromManualParts(
            $organization,
            $brandProfile,
            $admin,
            $payload
        );

        if ($opportunities->isEmpty()) {
            return back()->withErrors('没有生成新机会词，请检查词组和组合规则');
        }

        return redirect()
            ->route('admin.geo.workspace')
            ->with('message', '已生成 '.$opportunities->count().' 个手工拓词机会，可创建 AI 搜索批次');
    }

    /**
     * 创建一个批量 AI 搜索任务。
     */
    public function storeSearchRun(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'opportunity_ids' => ['required', 'array', 'min:1'],
            'opportunity_ids.*' => ['integer'],
            'platform_codes' => ['required', 'array', 'min:1'],
            'platform_codes.*' => ['string', 'max:80'],
        ], [
            'opportunity_ids.required' => '请先选择至少一个关键词机会',
            'platform_codes.required' => '请先选择至少一个 AI 平台',
        ]);

        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        if (! $brandProfile instanceof BrandProfile) {
            return back()->withErrors('请先保存品牌知识库，再创建 AI 搜索批次');
        }

        $opportunities = GeoKeywordOpportunity::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereIn('id', array_map('intval', (array) $payload['opportunity_ids']))
            ->orderByDesc('opportunity_score')
            ->get();
        if ($opportunities->isEmpty()) {
            return back()->withErrors('请选择当前企业下的关键词机会');
        }

        $platformCodes = $this->normalizePlatformCodes((array) $payload['platform_codes']);
        if ($platformCodes === []) {
            return back()->withErrors('请先选择至少一个 AI 平台');
        }

        DB::transaction(function () use ($admin, $organization, $brandProfile, $opportunities, $platformCodes, $payload): void {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                $name = 'GEO 搜索批次 - '.$brandProfile->brand_name.' - '.now()->format('m-d H:i');
            }

            $run = GeoAiSearchRun::query()->create([
                'organization_id' => $organization->id,
                'brand_profile_id' => $brandProfile->id,
                'created_by_admin_id' => $admin->id,
                'name' => $name,
                'status' => 'pending',
                'platform_codes' => $platformCodes,
                'points_cost' => $opportunities->count() * count($platformCodes),
                'total_questions' => $opportunities->count(),
            ]);

            foreach ($opportunities as $opportunity) {
                $run->questions()->create([
                    'geo_keyword_opportunity_id' => $opportunity->id,
                    'question' => $opportunity->keyword,
                    'intent' => $opportunity->intent,
                    'status' => 'pending',
                ]);
            }
        });

        return redirect()->route('admin.geo.workspace')->with('message', 'AI 搜索批次已创建，可开始运行');
    }

    /**
     * 运行批量 AI 搜索任务。
     */
    public function runSearchRun(int $runId, GeoSearchBatchRunner $runner): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->whereKey($runId)
            ->firstOrFail();

        if ($run->status === 'completed') {
            return redirect()->route('admin.geo.workspace')->with('message', 'AI 搜索批次已完成，无需重复执行');
        }

        try {
            $runner->run($run);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $this->diagnosisErrorMessage($exception),
            ])->save();

            return redirect()
                ->route('admin.geo.workspace')
                ->withErrors('AI 搜索批次执行失败：'.$this->diagnosisErrorMessage($exception));
        }

        return redirect()->route('admin.geo.workspace')->with('message', 'AI 搜索批次已执行，引用来源已抽取');
    }

    /**
     * 引用来源列表：用于采集页面内容和挑选可借鉴参考文章。
     */
    public function citationSources(): View
    {
        $organization = $this->resolveOrganization($this->currentAdmin());

        return view('admin.geo.citation-sources.index', [
            'pageTitle' => '引用来源库',
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'sources' => GeoCitationSource::query()
                ->where('organization_id', $organization->id)
                ->with('latestPageSnapshot.latestScore')
                ->orderByDesc('last_seen_at')
                ->latest()
                ->paginate(20),
            'referenceBriefs' => GeoWritingTask::query()
                ->where('organization_id', $organization->id)
                ->where('brief->source', 'reference_content')
                ->with('articleDrafts')
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * 引用来源详情：展示最近采集快照和质量评分。
     */
    public function showCitationSource(int $sourceId): View
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $source = $this->loadCitationSource($organization, $sourceId);
        $source->load([
            'searchAnswer.question',
            'searchAnswer.opportunity',
            'searchAnswer.searchRun.brandProfile',
            'pageSnapshots' => fn ($query) => $query->with('latestScore')->latest()->limit(10),
        ]);

        return view('admin.geo.citation-sources.show', [
            'pageTitle' => '引用来源详情',
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'source' => $source,
            'latestSnapshot' => $source->pageSnapshots->first(),
        ]);
    }

    /**
     * 采集一条引用来源的公开页面内容。
     */
    public function crawlCitationSource(int $sourceId, GeoReferencePageCrawler $crawler): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $source = $this->loadCitationSource($organization, $sourceId);
        $snapshot = $crawler->crawl($source);

        $source->forceFill([
            'title' => $snapshot->title ?: $source->title,
            'status' => $snapshot->crawl_status === 'succeeded' ? 'crawled' : 'crawl_failed',
            'metadata' => array_merge((array) $source->metadata, [
                'last_crawl_status' => $snapshot->crawl_status,
                'last_crawl_snapshot_id' => $snapshot->id,
                'last_crawl_error' => $snapshot->error_message,
            ]),
        ])->save();

        if ($snapshot->crawl_status !== 'succeeded') {
            return redirect()
                ->route('admin.geo.citation-sources.show', ['sourceId' => $source->id])
                ->withErrors('页面采集失败：'.$this->diagnosisErrorMessage(new \RuntimeException((string) $snapshot->error_message)));
        }

        return redirect()
            ->route('admin.geo.citation-sources.show', ['sourceId' => $source->id])
            ->with('message', '引用来源页面已采集');
    }

    /**
     * 批量采集引用来源页面。
     */
    public function batchCrawlCitationSources(Request $request, GeoReferencePageCrawler $crawler): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $sources = $this->selectedCitationSources($request, $organization);

        if ((bool) config('geoflow.geo_async_jobs', false)) {
            GeoBatchCrawlCitationSourcesJob::dispatch(
                (int) $organization->id,
                $sources->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all()
            );

            return redirect()
                ->route('admin.geo.citation-sources.index')
                ->with('message', '批量采集已加入队列：'.$sources->count().' 条');
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($sources as $source) {
            $snapshot = $crawler->crawl($source);
            $source->forceFill([
                'title' => $snapshot->title ?: $source->title,
                'status' => $snapshot->crawl_status === 'succeeded' ? 'crawled' : 'crawl_failed',
                'metadata' => array_merge((array) $source->metadata, [
                    'last_crawl_status' => $snapshot->crawl_status,
                    'last_crawl_snapshot_id' => $snapshot->id,
                    'last_crawl_error' => $snapshot->error_message,
                ]),
            ])->save();

            if ($snapshot->crawl_status === 'succeeded') {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return redirect()
            ->route('admin.geo.citation-sources.index')
            ->with('message', '批量采集完成：成功 '.$succeeded.' 条，失败 '.$failed.' 条');
    }

    /**
     * 给最近一次成功采集的引用页面打质量分。
     */
    public function scoreCitationSource(int $sourceId, GeoReferenceContentQualityScorer $scorer): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $source = $this->loadCitationSource($organization, $sourceId);
        $source->load(['searchAnswer.question', 'searchAnswer.opportunity', 'searchAnswer.searchRun.brandProfile']);

        $snapshot = $source->pageSnapshots()
            ->where('crawl_status', 'succeeded')
            ->latest()
            ->first();

        if (! $snapshot) {
            return back()->withErrors('请先成功采集页面内容，再执行质量评分');
        }

        $score = $scorer->scoreSnapshot($snapshot, $this->referenceScoringContext($source, $organization));

        return redirect()
            ->route('admin.geo.citation-sources.show', ['sourceId' => $source->id])
            ->with('message', '参考内容质量评分已生成：'.$score->total_score.' 分');
    }

    /**
     * 批量评分已成功采集的引用页面。
     */
    public function batchScoreCitationSources(Request $request, GeoReferenceContentQualityScorer $scorer): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $sources = $this->selectedCitationSources($request, $organization);

        if ((bool) config('geoflow.geo_async_jobs', false)) {
            GeoBatchScoreCitationSourcesJob::dispatch(
                (int) $organization->id,
                $sources->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all()
            );

            return redirect()
                ->route('admin.geo.citation-sources.index')
                ->with('message', '批量评分已加入队列：'.$sources->count().' 条');
        }

        $scored = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            $source->load(['searchAnswer.question', 'searchAnswer.opportunity', 'searchAnswer.searchRun.brandProfile']);
            $snapshot = $source->pageSnapshots()
                ->where('crawl_status', 'succeeded')
                ->latest()
                ->first();

            if (! $snapshot) {
                $skipped++;

                continue;
            }

            $scorer->scoreSnapshot($snapshot, $this->referenceScoringContext($source, $organization));
            $scored++;
        }

        return redirect()
            ->route('admin.geo.citation-sources.index')
            ->with('message', '批量评分完成：评分 '.$scored.' 条，跳过 '.$skipped.' 条');
    }

    /**
     * 从高分参考内容生成写作简报。
     */
    public function storeReferenceBrief(Request $request, GeoReferenceBriefBuilder $briefBuilder): RedirectResponse
    {
        $payload = $request->validate([
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer'],
            'title' => ['nullable', 'string', 'max:220'],
        ], [
            'source_ids.required' => '请先选择至少一个引用来源',
        ]);

        $organization = $this->resolveOrganization($this->currentAdmin());
        $sources = GeoCitationSource::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', array_map('intval', (array) $payload['source_ids']))
            ->with('latestPageSnapshot.latestScore')
            ->get();

        if ($sources->isEmpty()) {
            return back()->withErrors('请选择当前企业下的引用来源');
        }

        try {
            $brief = $briefBuilder->build($organization, $sources, $payload['title'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.citation-sources.index')
            ->with('message', '参考内容简报已生成：'.$brief->title);
    }

    /**
     * 从参考内容简报生成文章草稿。
     */
    public function generateReferenceBriefDraft(int $writingTaskId, GeoReferenceDraftGenerator $generator): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $writingTask = GeoWritingTask::query()
            ->where('organization_id', $organization->id)
            ->where('brief->source', 'reference_content')
            ->whereKey($writingTaskId)
            ->firstOrFail();

        try {
            $draft = $generator->generate($writingTask);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.citation-sources.index')
            ->with('message', '参考内容草稿已生成：'.$draft->title);
    }

    /**
     * 创建一条待执行的 GEO 诊断任务。
     */
    public function storeDiagnosis(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'keyword_ids' => ['required', 'array', 'min:1'],
            'keyword_ids.*' => ['integer'],
            'platform_codes' => ['required', 'array', 'min:1'],
            'platform_codes.*' => ['string', 'max:80'],
            'report_mode' => ['nullable', Rule::in(['visibility_only', 'with_recommendations'])],
        ], [
            'keyword_ids.required' => '请先选择至少一个关键词',
            'platform_codes.required' => '请先选择至少一个 AI 平台',
        ]);

        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        if (! $brandProfile instanceof BrandProfile) {
            return back()->withErrors('请先保存品牌知识库，再创建诊断任务');
        }

        $keywords = GeoKeyword::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', array_map('intval', (array) $payload['keyword_ids']))
            ->orderBy('id')
            ->get();
        if ($keywords->isEmpty()) {
            return back()->withErrors('请选择当前企业下的关键词');
        }

        $platformCodes = $this->normalizePlatformCodes((array) $payload['platform_codes']);
        if ($platformCodes === []) {
            return back()->withErrors('请先选择至少一个 AI 平台');
        }
        $reportMode = (string) ($payload['report_mode'] ?? 'with_recommendations');

        DB::transaction(function () use ($admin, $organization, $brandProfile, $keywords, $platformCodes, $reportMode): void {
            $task = GeoTask::query()->create([
                'organization_id' => $organization->id,
                'brand_profile_id' => $brandProfile->id,
                'created_by_admin_id' => $admin->id,
                'name' => 'GEO 诊断 - '.$brandProfile->brand_name.' - '.now()->format('m-d H:i'),
                'status' => 'pending',
                'points_cost' => $keywords->count() * count($platformCodes),
                'report_mode' => $reportMode,
            ]);

            foreach ($keywords as $keyword) {
                $task->questions()->create([
                    'geo_keyword_id' => $keyword->id,
                    'question' => $this->buildQuestion($brandProfile, $keyword),
                    'platform_codes' => $platformCodes,
                    'status' => 'pending',
                ]);
            }
        });

        return redirect()->route('admin.geo.workspace')->with('message', '诊断任务已创建，可执行真实或模拟 AI 平台诊断');
    }

    /**
     * 运行一条 GEO 诊断任务。
     */
    public function runDiagnosis(int $taskId, GeoDiagnosisRunner $runner): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = GeoTask::query()
            ->where('organization_id', $organization->id)
            ->whereKey($taskId)
            ->firstOrFail();

        if ($task->status === 'completed') {
            return redirect()->route('admin.geo.workspace')->with('message', '诊断任务已完成，无需重复执行');
        }

        if ((int) $organization->points < (int) $task->points_cost) {
            return back()->withErrors('点数不足，无法执行诊断任务');
        }

        try {
            $runner->run($task);
        } catch (Throwable $exception) {
            $task->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $this->diagnosisErrorMessage($exception),
            ])->save();

            return redirect()
                ->route('admin.geo.workspace')
                ->withErrors('诊断执行失败：'.$this->diagnosisErrorMessage($exception));
        }

        return redirect()->route('admin.geo.workspace')->with('message', '诊断任务已完成，报告已生成');
    }

    /**
     * 展示一条 GEO 诊断报告详情。
     */
    public function showReport(int $taskId): View
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = GeoTask::query()
            ->where('organization_id', $organization->id)
            ->whereKey($taskId)
            ->with([
                'brandProfile',
                'report',
                'answers' => fn ($query) => $query->orderBy('platform_code'),
                'answers.question',
                'answers.score',
            ])
            ->firstOrFail();

        abort_unless($task->report !== null, 404);

        return view('admin.geo.report', [
            'pageTitle' => $task->report->title,
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'task' => $task,
            'report' => $task->report,
            'platformNames' => $this->platformNameMap(),
            'writingTasks' => GeoWritingTask::query()
                ->where('geo_report_id', $task->report->id)
                ->with([
                    'articleDrafts.article',
                    'articleDrafts.audits' => fn ($query) => $query->latest()->limit(1),
                    'articleDrafts.publishRetests' => fn ($query) => $query->latest()->limit(1),
                ])
                ->latest()
                ->get(),
        ]);
    }

    /**
     * 根据 GEO 诊断报告生成一篇优化文章草稿。
     */
    public function generateArticleDraft(int $taskId, GeoArticleDraftGenerator $generator): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = GeoTask::query()
            ->where('organization_id', $organization->id)
            ->whereKey($taskId)
            ->with(['brandProfile', 'report', 'questions.geoKeyword'])
            ->firstOrFail();

        abort_unless($task->report !== null, 404);

        $generator->generate($task);

        return redirect()
            ->route('admin.geo.reports.show', ['taskId' => $task->id])
            ->with('message', '文章草稿已生成');
    }

    /**
     * 编辑一篇由 GEO 报告生成的文章草稿。
     */
    public function editArticleDraft(int $taskId, int $draftId): View
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = $this->loadReportTask($organization, $taskId);
        $draft = $this->loadDraftForReport($organization, $task, $draftId);

        return view('admin.geo.article-draft-edit', [
            'pageTitle' => '编辑文章草稿',
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'task' => $task,
            'report' => $task->report,
            'draft' => $draft,
        ]);
    }

    /**
     * 保存 GEO 文章草稿内容。
     */
    public function updateArticleDraft(Request $request, int $taskId, int $draftId): RedirectResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:220'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'content_markdown' => ['required', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
        ], [
            'title.required' => '请填写草稿标题',
            'content_markdown.required' => '请填写草稿正文',
        ]);

        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = $this->loadReportTask($organization, $taskId);
        $draft = $this->loadDraftForReport($organization, $task, $draftId);

        $markdown = trim((string) $payload['content_markdown']);
        $draft->update([
            'title' => trim((string) $payload['title']),
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'content_markdown' => $markdown,
            'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
            'seo_title' => trim((string) ($payload['seo_title'] ?? '')),
            'seo_description' => trim((string) ($payload['seo_description'] ?? '')),
            'status' => $draft->status === 'converted' ? 'converted' : 'draft',
        ]);

        return redirect()
            ->route('admin.geo.reports.show', ['taskId' => $task->id])
            ->with('message', '文章草稿已保存');
    }

    /**
     * 将 GEO 草稿写入现有文章管理，进入后续审核/发布流程。
     */
    public function convertArticleDraft(int $taskId, int $draftId, GeoArticlePublisher $publisher): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = $this->loadReportTask($organization, $taskId);
        $draft = $this->loadDraftForReport($organization, $task, $draftId);
        $article = $publisher->convertDraftToArticle($draft);

        return redirect()
            ->route('admin.articles.edit', ['articleId' => $article->id])
            ->with('message', 'GEO 草稿已转为正式文章');
    }

    /**
     * 对已转文章执行发布前 GEO 检查。
     */
    public function auditArticleDraft(int $taskId, int $draftId, GeoArticleAuditService $auditService): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = $this->loadReportTask($organization, $taskId);
        $draft = $this->loadDraftForReport($organization, $task, $draftId);

        if (! $draft->article) {
            return back()->withErrors('请先将草稿转为正式文章，再执行 GEO 检查');
        }

        $auditService->audit($task, $draft);

        return redirect()
            ->route('admin.geo.reports.show', ['taskId' => $task->id])
            ->with('message', '发布前 GEO 检查已完成');
    }

    public function retestArticleDraft(int $taskId, int $draftId, GeoPostPublishRetestRunner $retestRunner): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = $this->loadReportTask($organization, $taskId);
        $draft = $this->loadDraftForReport($organization, $task, $draftId);

        if (! $draft->article) {
            return back()->withErrors('请先将草稿转为正式文章，再执行发布后复测');
        }

        if ((bool) config('geoflow.geo_async_jobs', false)) {
            GeoPostPublishRetestJob::dispatch((int) $organization->id, (int) $task->id, (int) $draft->id);

            return redirect()
                ->route('admin.geo.reports.show', ['taskId' => $task->id])
                ->with('message', '发布后复测已加入队列');
        }

        $retestRunner->run($task, $draft);

        return redirect()
            ->route('admin.geo.reports.show', ['taskId' => $task->id])
            ->with('message', '发布后复测已完成');
    }

    private function currentAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function resolveOrganization(Admin $admin): Organization
    {
        $fallbackName = trim((string) ($admin->display_name ?: $admin->username)) ?: '默认企业';

        return Organization::query()->firstOrCreate(
            ['owner_admin_id' => $admin->id],
            [
                'name' => $fallbackName,
                'plan_code' => 'trial',
                'points' => 100,
                'balance' => 0,
                'status' => 'active',
            ]
        );
    }

    private function loadBrandProfile(Organization $organization): ?BrandProfile
    {
        return BrandProfile::query()
            ->where('organization_id', $organization->id)
            ->latest()
            ->first();
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function loadActiveChatAiModels(): Collection
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereNull('daily_limit')
                    ->orWhere('daily_limit', 0)
                    ->orWhereColumn('used_today', '<', 'daily_limit');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, GeoAiPlatform>
     */
    private function ensureDefaultPlatforms(): Collection
    {
        $defaults = [
            [
                'name' => 'DeepSeek 模拟',
                'code' => 'deepseek_mock',
                'api_mode' => 'mock',
                'cost_per_query' => 1,
            ],
            [
                'name' => 'Kimi 模拟',
                'code' => 'kimi_mock',
                'api_mode' => 'mock',
                'cost_per_query' => 1,
            ],
            [
                'name' => '通义千问模拟',
                'code' => 'qwen_mock',
                'api_mode' => 'mock',
                'cost_per_query' => 1,
            ],
        ];

        foreach ($defaults as $default) {
            GeoAiPlatform::query()->updateOrCreate(
                ['code' => $default['code']],
                $default + ['status' => 'active']
            );
        }

        return GeoAiPlatform::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function parseAliases(string $aliasesText): array
    {
        $normalized = str_replace(['，', '、', ';', '；'], "\n", $aliasesText);
        $aliases = preg_split('/\R|,/u', $normalized) ?: [];

        return collect($aliases)
            ->map(static fn (string $alias): string => trim($alias))
            ->filter(static fn (string $alias): bool => $alias !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     short_name: string,
     *     writing_directions: string,
     *     copy_types: list<string>,
     *     product_features: list<string>,
     *     brand_story: string,
     *     trust_proofs: list<string>,
     *     promotion_regions: list<string>,
     *     forbidden_claims: list<string>
     * }
     */
    private function extendedProfileFromPayload(array $payload): array
    {
        return [
            'short_name' => trim((string) ($payload['short_name'] ?? '')),
            'writing_directions' => trim((string) ($payload['writing_directions'] ?? '')),
            'copy_types' => $this->parseProfileList((string) ($payload['copy_types'] ?? '')),
            'product_features' => $this->parseProfileList((string) ($payload['product_features'] ?? '')),
            'brand_story' => trim((string) ($payload['brand_story'] ?? '')),
            'trust_proofs' => $this->parseProfileList((string) ($payload['trust_proofs'] ?? '')),
            'promotion_regions' => $this->parseProfileList((string) ($payload['promotion_regions'] ?? '')),
            'forbidden_claims' => $this->parseProfileList((string) ($payload['forbidden_claims'] ?? '')),
        ];
    }

    /**
     * @return list<string>
     */
    private function parseProfileList(string $text): array
    {
        $normalized = str_replace(['，', '、', ';', '；'], "\n", $text);
        $parts = preg_split('/\R|,/u', $normalized) ?: [];

        return collect($parts)
            ->map(static fn (string $part): string => trim($part))
            ->filter(static fn (string $part): bool => $part !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return array<int, string>
     */
    private function normalizePlatformCodes(array $codes): array
    {
        $mockCodes = $this->ensureDefaultPlatforms()
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
        $realModelCodes = $this->loadActiveChatAiModels()
            ->map(static fn (AiModel $model): string => 'ai_model:'.(int) $model->id)
            ->all();
        $activeCodes = array_merge($mockCodes, $realModelCodes);

        return collect($codes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->intersect($activeCodes)
            ->values()
            ->all();
    }

    private function buildQuestion(BrandProfile $brandProfile, GeoKeyword $keyword): string
    {
        if ($keyword->type === 'question') {
            return (string) $keyword->keyword;
        }

        $area = trim((string) $brandProfile->service_area);
        $prefix = $area !== '' ? '在'.$area : '';

        return $prefix.'选择'.$keyword->keyword.'时，哪些品牌值得优先了解？';
    }

    /**
     * @return array<string, string>
     */
    private function platformNameMap(): array
    {
        $names = [
            'deepseek_mock' => 'DeepSeek 模拟',
            'kimi_mock' => 'Kimi 模拟',
            'qwen_mock' => '通义千问模拟',
        ];

        AiModel::query()
            ->orderBy('id')
            ->get(['id', 'name', 'model_id'])
            ->each(function (AiModel $model) use (&$names): void {
                $label = trim((string) $model->name);
                $modelId = trim((string) $model->model_id);
                if ($modelId !== '') {
                    $label .= $label !== '' ? ' · '.$modelId : $modelId;
                }

                $names['ai_model:'.(int) $model->id] = $label !== '' ? $label : '真实 AI 模型 #'.(int) $model->id;
            });

        return $names;
    }

    private function diagnosisErrorMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return $exception::class;
        }

        return mb_substr($message, 0, 1000);
    }

    private function loadReportTask(Organization $organization, int $taskId): GeoTask
    {
        $task = GeoTask::query()
            ->where('organization_id', $organization->id)
            ->whereKey($taskId)
            ->with(['brandProfile', 'report'])
            ->firstOrFail();

        abort_unless($task->report !== null, 404);

        return $task;
    }

    private function loadDraftForReport(Organization $organization, GeoTask $task, int $draftId): GeoArticleDraft
    {
        return GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->whereKey($draftId)
            ->whereHas('writingTask', function ($query) use ($task): void {
                $query->where('geo_report_id', $task->report->id);
            })
            ->with([
                'article',
                'writingTask',
                'audits' => fn ($query) => $query->latest(),
                'publishRetests' => fn ($query) => $query->latest(),
            ])
            ->firstOrFail();
    }

    private function loadCitationSource(Organization $organization, int $sourceId): GeoCitationSource
    {
        return GeoCitationSource::query()
            ->where('organization_id', $organization->id)
            ->whereKey($sourceId)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, GeoCitationSource>
     */
    private function selectedCitationSources(Request $request, Organization $organization): Collection
    {
        $payload = $request->validate([
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer'],
        ], [
            'source_ids.required' => '请先选择至少一个引用来源',
        ]);

        $sources = GeoCitationSource::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', array_map('intval', (array) $payload['source_ids']))
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        if ($sources->isEmpty()) {
            abort(404);
        }

        return $sources;
    }

    /**
     * @return array{query: string, keywords: list<string>, brand_names: list<string>, competitor_names: list<string>}
     */
    private function referenceScoringContext(GeoCitationSource $source, Organization $organization): array
    {
        $answer = $source->searchAnswer;
        $brandProfile = $answer?->searchRun?->brandProfile ?? $this->loadBrandProfile($organization);
        $brandNames = $brandProfile instanceof BrandProfile
            ? array_values(array_filter(array_merge([$brandProfile->brand_name], (array) $brandProfile->aliases)))
            : [];
        $brandKeywords = $brandProfile instanceof BrandProfile
            ? $this->referenceTermsFromBrandProfile($brandProfile)
            : [];

        return [
            'query' => (string) ($answer?->question?->question ?? $answer?->opportunity?->keyword ?? ''),
            'keywords' => array_values(array_filter(array_merge([
                (string) ($answer?->opportunity?->keyword ?? ''),
                (string) ($answer?->opportunity?->intent ?? ''),
                (string) $source->domain,
            ], $brandKeywords))),
            'brand_names' => $brandNames,
            'competitor_names' => array_values(array_filter((array) ($answer?->competitors_mentioned ?? []))),
        ];
    }

    /**
     * @return list<string>
     */
    private function referenceTermsFromBrandProfile(BrandProfile $brandProfile): array
    {
        $extendedProfile = (array) ($brandProfile->extended_profile ?? []);
        $rawTerms = [
            $brandProfile->service_area,
            $brandProfile->products,
            $brandProfile->advantages,
            $brandProfile->pain_points,
            $extendedProfile['short_name'] ?? '',
            $extendedProfile['writing_directions'] ?? '',
            $extendedProfile['brand_story'] ?? '',
            implode(' ', (array) ($extendedProfile['product_features'] ?? [])),
            implode(' ', (array) ($extendedProfile['trust_proofs'] ?? [])),
            implode(' ', (array) ($extendedProfile['promotion_regions'] ?? [])),
        ];

        return collect($rawTerms)
            ->flatMap(static fn (mixed $value): array => preg_split('/[\s,，。；;、]+/u', (string) $value) ?: [])
            ->map(static fn (mixed $term): string => trim((string) $term))
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{latest_score: int|null, average_score: int|null, delta: int|null, reports_count: int}
     */
    private function trendMetrics(Organization $organization): array
    {
        $reports = GeoReport::query()
            ->whereHas('geoTask', fn ($query) => $query->where('organization_id', $organization->id))
            ->where('status', 'ready')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'total_score', 'created_at']);

        if ($reports->isEmpty()) {
            return [
                'latest_score' => null,
                'average_score' => null,
                'delta' => null,
                'reports_count' => 0,
            ];
        }

        $latest = $reports->first();
        $previous = $reports->skip(1)->first();

        return [
            'latest_score' => (int) $latest->total_score,
            'average_score' => (int) round((float) $reports->avg('total_score')),
            'delta' => $previous ? (int) $latest->total_score - (int) $previous->total_score : null,
            'reports_count' => $reports->count(),
        ];
    }

    /**
     * @return array{drafts: int, converted: int, audits: int, conversion_label: string}
     */
    private function pipelineMetrics(Organization $organization): array
    {
        $drafts = GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->count();
        $converted = GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'converted')
            ->count();
        $audits = GeoArticleAudit::query()
            ->where('organization_id', $organization->id)
            ->count();

        return [
            'drafts' => $drafts,
            'converted' => $converted,
            'audits' => $audits,
            'conversion_label' => $converted.' / '.$drafts,
        ];
    }
}
