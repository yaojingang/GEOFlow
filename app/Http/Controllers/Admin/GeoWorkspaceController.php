<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeoBatchCrawlCitationSourcesJob;
use App\Jobs\GeoBatchScoreCitationSourcesJob;
use App\Jobs\GeoPostPublishRetestJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\BrandProfile;
use App\Models\Category;
use App\Models\GeoAiPlatform;
use App\Models\GeoAiSearchAnswer;
use App\Models\GeoAiSearchRun;
use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoKeyword;
use App\Models\GeoKeywordOpportunity;
use App\Models\GeoPublishRecord;
use App\Models\GeoPublishRetest;
use App\Models\GeoReport;
use App\Models\GeoTask;
use App\Models\GeoWritingTask;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Models\TitleLibrary;
use App\Services\Geo\GeoArticleAuditService;
use App\Services\Geo\GeoArticleDraftGenerator;
use App\Services\Geo\GeoArticlePublisher;
use App\Services\Geo\GeoArticlePublishPackageExporter;
use App\Services\Geo\GeoArticleVisualImageInserter;
use App\Services\Geo\GeoArticleVisualPublishPackBuilder;
use App\Services\Geo\GeoDiagnosisRunner;
use App\Services\Geo\GeoExternalQaInspectionBuilder;
use App\Services\Geo\GeoKeywordCombinationService;
use App\Services\Geo\GeoKeywordDiscoveryService;
use App\Services\Geo\GeoOpportunityMaterialSyncService;
use App\Services\Geo\GeoPostPublishRetestRunner;
use App\Services\Geo\GeoPublishableDraftPolisher;
use App\Services\Geo\GeoReferenceBriefBuilder;
use App\Services\Geo\GeoReferenceContentAnalyzer;
use App\Services\Geo\GeoReferenceContentQualityScorer;
use App\Services\Geo\GeoReferenceDraftGenerator;
use App\Services\Geo\GeoReferenceImitationDraftGenerator;
use App\Services\Geo\GeoReferencePageCrawler;
use App\Services\Geo\GeoSearchBatchRunner;
use App\Services\Geo\GeoWebWorkbenchClient;
use App\Services\Geo\GeoYixiaoerContentOverviewService;
use App\Services\Geo\GeoYixiaoerHandoffService;
use App\Support\AdminWeb;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class GeoWorkspaceController extends Controller
{
    /**
     * GEO MVP 工作台：品牌资料、关键词和诊断任务的第一版闭环入口。
     */
    public function index(Request $request, GeoYixiaoerContentOverviewService $contentOverviewService): View
    {
        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $this->unlockStaleSearchRuns($organization);
        $brandProfile = $this->loadBrandProfile($organization);
        $platforms = $this->ensureDefaultPlatforms();
        $realAiModels = $this->loadActiveChatAiModels();
        $webWorkbenchClient = app(GeoWebWorkbenchClient::class);
        $publishedContentKeyword = trim((string) $request->query('published_content_keyword', ''));
        $publishedContentPlatform = trim((string) $request->query('published_content_platform', ''));
        $shouldLoadPublishedContent = $request->boolean('published_content_overview')
            || $publishedContentKeyword !== ''
            || $publishedContentPlatform !== '';

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
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'citationSources' => GeoCitationSource::query()
                ->where('organization_id', $organization->id)
                ->with('latestPageSnapshot.latestScore')
                ->orderByDesc('last_seen_at')
                ->latest()
                ->limit(12)
                ->get(),
            'webWorkbenchStatus' => $webWorkbenchClient->status(3),
            'webWorkbenchCommand' => $webWorkbenchClient->commandPath(),
            'trendMetrics' => $this->trendMetrics($organization),
            'externalInspection' => $this->externalInspectionDashboard($organization),
            'pipelineMetrics' => $this->pipelineMetrics($organization),
            'geoArticleWorkspace' => $this->geoArticleWorkspace($organization),
            'geoMaterialWorkspace' => $this->geoMaterialWorkspace($organization),
            'yixiaoerContentOverview' => $shouldLoadPublishedContent
                ? $contentOverviewService->summary(50, $publishedContentKeyword, $publishedContentPlatform)
                : $contentOverviewService->deferredSummary($publishedContentKeyword, $publishedContentPlatform),
        ]);
    }

    public function openWebWorkbench(Request $request, GeoWebWorkbenchClient $webWorkbenchClient): RedirectResponse|JsonResponse
    {
        $payload = $request->validate([
            'platform_id' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/'],
            'return_tab' => ['nullable', 'string', 'max:40'],
        ]);
        $platformId = trim((string) ($payload['platform_id'] ?? ''));
        $returnUrl = route('admin.geo.workspace').'#'.$this->workspaceReturnTab((string) ($payload['return_tab'] ?? 'ai-platforms'));

        try {
            $webWorkbenchClient->openUi($platformId !== '' ? $platformId : null);
        } catch (Throwable $exception) {
            return redirect()
                ->to($returnUrl)
                ->withErrors('搜索软件启动失败：'.$this->diagnosisErrorMessage($exception));
        }

        $message = $platformId !== ''
            ? $this->webWorkbenchPlatformLabel($platformId).' 登录窗口已打开'
            : '本机多平台 AI 搜索工作台已打开';

        if ($request->expectsJson()) {
            return response()->json($this->webWorkbenchPlatformStatusPayload($webWorkbenchClient, $message));
        }

        return redirect()
            ->to($returnUrl)
            ->with('message', $message);
    }

    public function checkWebWorkbenchLogins(Request $request, GeoWebWorkbenchClient $webWorkbenchClient): RedirectResponse|JsonResponse
    {
        $payload = $request->validate([
            'platform_ids' => ['nullable', 'array'],
            'platform_ids.*' => ['string', 'max:80', 'regex:/^[a-z0-9_-]+$/'],
            'return_tab' => ['nullable', 'string', 'max:40'],
        ]);
        $platformIds = collect((array) ($payload['platform_ids'] ?? []))
            ->map(static fn (mixed $platformId): string => trim((string) $platformId))
            ->filter(static fn (string $platformId): bool => $platformId !== '')
            ->unique()
            ->values()
            ->all();
        $returnUrl = route('admin.geo.workspace').'#'.$this->workspaceReturnTab((string) ($payload['return_tab'] ?? 'external-qa'));

        try {
            $webWorkbenchClient->startLoginCheck($platformIds);
        } catch (Throwable $exception) {
            return redirect()
                ->to($returnUrl)
                ->withErrors('登录状态检测失败：'.$this->diagnosisErrorMessage($exception));
        }

        $message = $platformIds === []
            ? '登录状态检测已启动：正在后台检查全部平台'
            : '登录状态检测已启动：正在后台检查 '.count($platformIds).' 个平台';

        if ($request->expectsJson()) {
            return response()->json($this->webWorkbenchPlatformStatusPayload($webWorkbenchClient, $message));
        }

        return redirect()
            ->to($returnUrl)
            ->with('message', $message.'，稍后刷新页面查看结果');
    }

    public function webWorkbenchPlatformStatuses(GeoWebWorkbenchClient $webWorkbenchClient): JsonResponse
    {
        return response()->json($this->webWorkbenchPlatformStatusPayload($webWorkbenchClient));
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

        return $this->redirectToWorkspace($request, '品牌知识库已保存');
    }

    /**
     * 添加一个 GEO 关键词或问题词。
     */
    public function storeKeyword(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'keywords_text' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['industry', 'brand', 'competitor', 'question'])],
            'intent' => ['nullable', 'string', 'max:80'],
        ], [
            'type.in' => '关键词类型不正确',
        ]);

        $organization = $this->resolveOrganization($this->currentAdmin());
        $keywordValues = $this->parseKeywordLines((string) ($payload['keywords_text'] ?? ''));
        $singleKeyword = trim((string) ($payload['keyword'] ?? ''));
        if ($singleKeyword !== '') {
            array_unshift($keywordValues, $singleKeyword);
        }

        $keywordValues = collect($keywordValues)
            ->map(static fn (string $keyword): string => trim($keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->take(80)
            ->values();

        if ($keywordValues->isEmpty()) {
            return back()->withErrors('请填写关键词');
        }

        $keywordValues->each(function (string $keyword) use ($organization, $payload): void {
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
        });

        return $this->redirectToWorkspace($request, '已加入 '.$keywordValues->count().' 个关键词到 GEO 关键词库');
    }

    /**
     * 基于企业资料批量生成 GEO 关键词机会。
     */
    public function generateOpportunities(
        Request $request,
        GeoKeywordDiscoveryService $discoveryService,
        GeoOpportunityMaterialSyncService $materialSyncService
    ): RedirectResponse {
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
        $syncResult = $materialSyncService->sync($organization, $brandProfile, $opportunities);

        return redirect()
            ->route('admin.geo.workspace')
            ->with('message', '已生成 '.$opportunities->count().' 个关键词机会，并同步到关键词库/标题库（新增 '
                .$syncResult['keywords_added'].' 词 / '.$syncResult['titles_added'].' 标题）');
    }

    /**
     * 根据 ABCDEF 词组手工拓展 GEO 关键词机会。
     */
    public function expandOpportunities(
        Request $request,
        GeoKeywordCombinationService $combinationService,
        GeoOpportunityMaterialSyncService $materialSyncService
    ): RedirectResponse {
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
        $syncResult = $materialSyncService->sync($organization, $brandProfile, $opportunities);

        return redirect()
            ->route('admin.geo.workspace')
            ->with('message', '已生成 '.$opportunities->count().' 个手工拓词机会，并同步到关键词库/标题库（新增 '
                .$syncResult['keywords_added'].' 词 / '.$syncResult['titles_added'].' 标题）');
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
        $this->unlockStaleSearchRuns($organization);
        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->whereKey($runId)
            ->firstOrFail();
        $isExternalInspection = $this->isExternalInspectionRun($run);

        if ($run->status === 'completed') {
            return redirect()
                ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
                ->with('message', $isExternalInspection ? '外部问答检视已完成，无需重复执行' : 'AI 搜索批次已完成，无需重复执行');
        }

        if ($run->status === 'running') {
            return redirect()
                ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
                ->with('message', $isExternalInspection ? '外部问答检视正在运行，请稍后刷新结果' : 'AI 搜索批次正在运行，请稍后刷新进度');
        }

        try {
            $runner->assertEnoughPoints($run);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
                ->withErrors($exception->getMessage());
        }

        if ($this->shouldRunSearchRunInBackground($run, $isExternalInspection)) {
            $this->prepareSearchRunForBackground($run);

            try {
                $this->launchSearchRunWorker($run);
            } catch (Throwable $exception) {
                $run->forceFill([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => $this->diagnosisErrorMessage($exception),
                ])->save();

                return redirect()
                    ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
                    ->withErrors('外部问答检视后台启动失败：'.$this->diagnosisErrorMessage($exception));
            }

            return redirect()
                ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
                ->with('message', '外部问答检视已在后台运行，页面会自动刷新进度');
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
                ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
                ->withErrors(($isExternalInspection ? '外部问答检视执行失败：' : 'AI 搜索批次执行失败：').$this->diagnosisErrorMessage($exception));
        }

        return redirect()
            ->to($this->searchRunRedirectUrl($run, $isExternalInspection))
            ->with('message', $isExternalInspection ? '外部问答检视已完成，可查看原始回答与引用证据' : 'AI 搜索批次已执行，引用来源已抽取');
    }

    /**
     * 从真实用户问题矩阵创建外部问答检视批次。
     */
    public function storeExternalInspection(Request $request, GeoExternalQaInspectionBuilder $builder): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'questions_text' => ['required', 'string', 'max:8000'],
            'target_keyword_hit_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'platform_codes' => ['required', 'array', 'min:1'],
            'platform_codes.*' => ['string', 'max:80'],
        ], [
            'questions_text.required' => '请填写外部问答检视的问题矩阵',
            'target_keyword_hit_rate.integer' => '预期关键词命中率必须是 0-100 的整数',
            'target_keyword_hit_rate.min' => '预期关键词命中率不能低于 0%',
            'target_keyword_hit_rate.max' => '预期关键词命中率不能高于 100%',
            'platform_codes.required' => '请先选择至少一个 AI 平台',
        ]);

        $admin = $this->currentAdmin();
        $organization = $this->resolveOrganization($admin);
        $brandProfile = $this->loadBrandProfile($organization);
        if (! $brandProfile instanceof BrandProfile) {
            return back()->withErrors('请先保存品牌知识库，再创建外部问答检视');
        }

        $platformCodes = $this->normalizePlatformCodes((array) $payload['platform_codes']);
        if ($platformCodes === []) {
            return back()->withErrors('请先选择至少一个已启用 AI 平台');
        }

        try {
            $run = $builder->create(
                $admin,
                $organization,
                $brandProfile,
                (string) ($payload['name'] ?? ''),
                (string) $payload['questions_text'],
                $platformCodes,
                (int) ($payload['target_keyword_hit_rate'] ?? 70)
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        $message = $run->wasRecentlyCreated
            ? '外部问答检视已创建：'.$run->name
            : '外部问答检视已存在，已打开已有批次：'.$run->name;

        return redirect()
            ->to(route('admin.geo.workspace').'#external-qa')
            ->with('message', $message);
    }

    /**
     * 展示单个 AI 搜索/外部问答检视批次的原始证据。
     */
    public function showSearchRun(int $runId): View
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $this->unlockStaleSearchRuns($organization);
        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->whereKey($runId)
            ->with([
                'brandProfile',
                'questions' => fn ($query) => $query->with([
                    'opportunity',
                    'answers' => fn ($answerQuery) => $answerQuery->orderBy('platform_code'),
                ])->orderBy('id'),
            ])
            ->firstOrFail();

        return view('admin.geo.search-run', [
            'pageTitle' => '外部问答检视证据',
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'run' => $run,
            'platformNames' => $this->platformNameMap(),
            'evidenceMetrics' => $this->searchRunEvidenceMetrics($run),
        ]);
    }

    public function destroySearchRun(Request $request, int $runId): RedirectResponse|JsonResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->whereKey($runId)
            ->with('answers:id,geo_ai_search_run_id')
            ->first();

        if (! $run instanceof GeoAiSearchRun) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => '检视批次已删除或不存在',
                    'run_id' => $runId,
                    'already_deleted' => true,
                ]);
            }

            return redirect()
                ->to(route('admin.geo.workspace').'#external-qa')
                ->with('message', '检视批次已删除或不存在');
        }

        if ($run->status === 'running') {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => '检视正在运行，完成或超时后再删除',
                    'run_id' => $runId,
                ], 409);
            }

            return redirect()
                ->to(route('admin.geo.workspace').'#external-qa')
                ->withErrors('检视正在运行，完成或超时后再删除');
        }

        $runsToDelete = $this->matchingSearchRunsForDeletion($run);
        $runIds = $runsToDelete
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        DB::transaction(function () use ($runsToDelete, $runIds): void {
            $answerIds = $runsToDelete
                ->flatMap(fn (GeoAiSearchRun $run): Collection => $run->answers)
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            if ($answerIds !== []) {
                GeoCitationSource::query()
                    ->whereIn('geo_ai_search_answer_id', $answerIds)
                    ->update(['geo_ai_search_answer_id' => null]);
            }

            GeoAiSearchRun::query()
                ->whereIn('id', $runIds)
                ->delete();
        });

        $deletedCount = count($runIds);
        $message = $deletedCount > 1
            ? '已删除 '.$deletedCount.' 个同名重复检视批次'
            : '检视批次已删除';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'run_id' => $runId,
                'deleted_count' => $deletedCount,
                'deleted_run_ids' => $runIds,
            ]);
        }

        return redirect()
            ->to(route('admin.geo.workspace').'#external-qa')
            ->with('message', $message);
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
                ->with(['latestPageSnapshot.latestScore', 'latestReferenceAnalysis'])
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
            'referenceAnalyses' => fn ($query) => $query->latest()->limit(5),
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
     * 把已评分的高分来源落成本地档案，并拆解文章结构与被引用原因。
     */
    public function analyzeCitationSource(int $sourceId, GeoReferenceContentAnalyzer $analyzer): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $source = $this->loadCitationSource($organization, $sourceId);

        try {
            $analysis = $analyzer->analyze($organization, $source);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.citation-sources.show', ['sourceId' => $source->id])
            ->with('message', '本地分析档案已生成：'.$analysis->markdown_path);
    }

    /**
     * 基于本地分析档案，复用高分来源结构生成一篇可编辑仿写草稿。
     */
    public function generateCitationSourceImitationDraft(int $sourceId, GeoReferenceImitationDraftGenerator $generator): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $source = $this->loadCitationSource($organization, $sourceId);

        try {
            $draft = $generator->generate($organization, $source);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '结构仿写草稿已生成：'.$draft->title);
    }

    /**
     * 基于高分来源直接生成可发布正文。
     */
    public function generateCitationSourcePublishableDraft(
        int $sourceId,
        GeoReferenceImitationDraftGenerator $generator,
        GeoPublishableDraftPolisher $polisher
    ): RedirectResponse {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $source = $this->loadCitationSource($organization, $sourceId);

        try {
            $draft = $generator->generate($organization, $source);
            $draft = $polisher->polish($draft);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '可发布正文已生成：'.$draft->title);
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

        return $this->redirectToWorkspace($request, '诊断任务已创建，可执行真实或模拟 AI 平台诊断');
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
                    'articleDrafts.publishRecords' => fn ($query) => $query->latest()->limit(1),
                    'articleDrafts.publishRecords.publishTarget',
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
            'backUrl' => route('admin.geo.reports.show', ['taskId' => (int) $task->id]),
            'backLabel' => '返回诊断报告',
            'updateRoute' => route('admin.geo.reports.article-drafts.update', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]),
            'convertRoute' => route('admin.geo.reports.article-drafts.convert', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]),
            'publishableRoute' => null,
            'layoutRoute' => null,
            'visualPackRoute' => null,
            'visualInsertRoute' => null,
            'publishPackageRoute' => null,
            'yixiaoerDistributeRoute' => null,
        ]);
    }

    /**
     * 编辑一篇没有挂到诊断报告下的 GEO 草稿，例如引用来源结构仿写草稿。
     */
    public function editStandaloneArticleDraft(int $draftId): View
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        return view('admin.geo.article-draft-edit', [
            'pageTitle' => '编辑文章草稿',
            'activeMenu' => 'geo',
            'adminSiteName' => AdminWeb::siteName(),
            'organization' => $organization,
            'task' => null,
            'report' => null,
            'draft' => $draft,
            'backUrl' => $this->standaloneDraftBackUrl($draft),
            'backLabel' => '返回引用来源',
            'updateRoute' => route('admin.geo.article-drafts.update', ['draftId' => (int) $draft->id]),
            'convertRoute' => route('admin.geo.article-drafts.convert', ['draftId' => (int) $draft->id]),
            'publishableRoute' => route('admin.geo.article-drafts.publishable', ['draftId' => (int) $draft->id]),
            'layoutRoute' => route('admin.geo.article-drafts.layout', ['draftId' => (int) $draft->id]),
            'visualPackRoute' => route('admin.geo.article-drafts.visual-pack', ['draftId' => (int) $draft->id]),
            'visualInsertRoute' => route('admin.geo.article-drafts.visual-pack.insert-images', ['draftId' => (int) $draft->id]),
            'publishPackageRoute' => route('admin.geo.article-drafts.publish-package', ['draftId' => (int) $draft->id]),
            'yixiaoerDistributeRoute' => route('admin.geo.article-drafts.yixiaoer-distribute', ['draftId' => (int) $draft->id]),
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

        $this->updateDraftFromPayload($draft, $payload);

        return redirect()
            ->route('admin.geo.reports.show', ['taskId' => $task->id])
            ->with('message', '文章草稿已保存');
    }

    /**
     * 保存独立 GEO 文章草稿内容。
     */
    public function updateStandaloneArticleDraft(Request $request, int $draftId): RedirectResponse
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
        $draft = $this->loadStandaloneDraft($organization, $draftId);
        $this->updateDraftFromPayload($draft, $payload);

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
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

    public function convertStandaloneArticleDraft(int $draftId, GeoArticlePublisher $publisher): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);
        $article = $publisher->convertDraftToArticle($draft);

        return redirect()
            ->route('admin.articles.edit', ['articleId' => $article->id])
            ->with('message', 'GEO 草稿已转为正式文章');
    }

    public function polishStandaloneArticleDraft(int $draftId, GeoPublishableDraftPolisher $polisher): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        try {
            $draft = $polisher->polish($draft);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '可发布正文已生成：'.$draft->title);
    }

    public function layoutStandaloneArticleDraft(int $draftId, GeoPublishableDraftPolisher $polisher): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        try {
            $draft = $polisher->polish($draft);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '正文排版已优化：'.$draft->title);
    }

    public function generateStandaloneArticleVisualPack(int $draftId, GeoArticleVisualPublishPackBuilder $builder): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        try {
            $draft = $builder->build($draft);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '配图与发布包已生成：'.$draft->title);
    }

    public function insertStandaloneArticleVisualImages(int $draftId, GeoArticleVisualImageInserter $inserter): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        try {
            $draft = $inserter->insert($draft);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '配图已植入正文：'.$draft->title);
    }

    public function exportStandaloneArticlePublishPackage(int $draftId, GeoArticlePublishPackageExporter $exporter): RedirectResponse
    {
        $organization = $this->resolveOrganization($this->currentAdmin());
        $draft = $this->loadStandaloneDraft($organization, $draftId);

        try {
            $draft = $exporter->export($draft);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.article-drafts.edit', ['draftId' => $draft->id])
            ->with('message', '发布包已导出：'.$draft->title);
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

    public function createYixiaoerHandoff(int $taskId, int $draftId, Request $request, GeoYixiaoerHandoffService $handoffService): RedirectResponse
    {
        $payload = $request->validate([
            'platform_codes' => ['required', 'array', 'min:1'],
            'platform_codes.*' => ['required', Rule::in(['xiaohongshu', 'douyin', 'shipinhao', 'bilibili'])],
        ], [
            'platform_codes.required' => '请至少选择一个蚁小二目标平台',
        ]);

        $organization = $this->resolveOrganization($this->currentAdmin());
        $task = $this->loadReportTask($organization, $taskId);
        $draft = $this->loadDraftForReport($organization, $task, $draftId);

        try {
            $handoffService->create($task, $draft, (array) $payload['platform_codes']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.geo.reports.show', ['taskId' => $task->id])
            ->with('message', '蚁小二发布交接已生成');
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

    private function redirectToWorkspace(Request $request, string $message): RedirectResponse
    {
        $tab = trim((string) $request->input('return_tab', ''));
        if (in_array($tab, ['overview', 'external-qa', 'search', 'ai-platforms', 'setup', 'articles', 'materials', 'tasks'], true)) {
            return redirect()
                ->to(route('admin.geo.workspace').'#'.$tab)
                ->with('message', $message);
        }

        return redirect()->route('admin.geo.workspace')->with('message', $message);
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
                'name' => GeoWebWorkbenchClient::PLATFORM_NAME,
                'code' => GeoWebWorkbenchClient::PLATFORM_CODE,
                'api_mode' => 'web_workbench',
                'cost_per_query' => 1,
            ],
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
            ->get()
            ->sortBy(static fn (GeoAiPlatform $platform): int => $platform->code === GeoWebWorkbenchClient::PLATFORM_CODE ? 0 : 1)
            ->values();
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
     * @return list<string>
     */
    private function parseKeywordLines(string $keywordsText): array
    {
        $normalized = str_replace(['，', '、', ';', '；'], "\n", $keywordsText);
        $keywords = preg_split('/\R|,/u', $normalized) ?: [];

        return collect($keywords)
            ->map(static fn (string $keyword): string => trim($keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->map(static fn (string $keyword): string => mb_substr($keyword, 0, 255))
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
        $webWorkbenchEnabled = in_array(GeoWebWorkbenchClient::PLATFORM_CODE, $activeCodes, true);

        return collect($codes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->filter(static function (string $code) use ($activeCodes, $webWorkbenchEnabled): bool {
                if (in_array($code, $activeCodes, true)) {
                    return true;
                }

                return $webWorkbenchEnabled
                    && str_starts_with($code, GeoWebWorkbenchClient::PLATFORM_CODE.':')
                    && preg_match('/^'.preg_quote(GeoWebWorkbenchClient::PLATFORM_CODE, '/').':[a-z0-9_-]+$/', $code) === 1;
            })
            ->values()
            ->all();
    }

    private function workspaceReturnTab(string $tab): string
    {
        return in_array($tab, ['overview', 'external-qa', 'search', 'setup', 'articles', 'tasks', 'materials', 'ai-platforms'], true)
            ? $tab
            : 'ai-platforms';
    }

    private function webWorkbenchPlatformLabel(string $platformId): string
    {
        return match ($platformId) {
            'chatgpt' => 'ChatGPT',
            'kimi' => 'Kimi',
            'deepseek' => 'DeepSeek',
            'doubao' => '豆包',
            'qianwen' => '千问',
            'yuanbao' => '腾讯元宝',
            'wenxiaoyan' => '文小言',
            default => $platformId,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function webWorkbenchPlatformStatusPayload(GeoWebWorkbenchClient $webWorkbenchClient, string $message = ''): array
    {
        $status = $webWorkbenchClient->status(3);

        return [
            'ok' => (bool) ($status['ok'] ?? false),
            'message' => $message,
            'platforms' => collect((array) ($status['platforms'] ?? []))->values()->all(),
            'html' => $this->renderWebWorkbenchPlatformOptions($status),
            'error' => (string) ($status['error'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function renderWebWorkbenchPlatformOptions(array $status): string
    {
        $platforms = $this->ensureDefaultPlatforms();

        return view('admin.geo.partials.web-workbench-platform-options', [
            'realSearchPlatforms' => $platforms->where('api_mode', 'web_workbench')->values(),
            'mockSearchPlatforms' => $platforms->where('api_mode', 'mock')->values(),
            'realAiModels' => $this->loadActiveChatAiModels(),
            'webWorkbenchPlatformStatuses' => collect((array) ($status['platforms'] ?? [])),
            'webWorkbenchStatus' => $status,
        ])->render();
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
            GeoWebWorkbenchClient::PLATFORM_CODE => GeoWebWorkbenchClient::PLATFORM_NAME,
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
                'publishRecords' => fn ($query) => $query->with('publishTarget')->latest(),
            ])
            ->firstOrFail();
    }

    private function loadStandaloneDraft(Organization $organization, int $draftId): GeoArticleDraft
    {
        return GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->whereKey($draftId)
            ->whereHas('writingTask', function ($query): void {
                $query->whereNull('geo_report_id');
            })
            ->with([
                'article',
                'writingTask',
                'audits' => fn ($query) => $query->latest(),
                'publishRetests' => fn ($query) => $query->latest(),
                'publishRecords' => fn ($query) => $query->with('publishTarget')->latest(),
            ])
            ->firstOrFail();
    }

    private function standaloneDraftBackUrl(GeoArticleDraft $draft): string
    {
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $sourceId = (int) ($brief['source_id'] ?? 0);
        if ($sourceId > 0) {
            return route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]);
        }

        return route('admin.geo.citation-sources.index');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateDraftFromPayload(GeoArticleDraft $draft, array $payload): void
    {
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
     * @return array{
     *     runs:Collection<int,GeoAiSearchRun>,
     *     latest:GeoAiSearchRun|null,
     *     total_runs:int,
     *     total_answers:int,
     *     brand_mention_rate:int,
     *     keyword_hit_rate:int,
     *     target_keyword_hit_rate:int|null,
     *     citation_rate:int,
     *     average_score:int|null,
     *     platform_count:int,
     *     keyword_hit_trend:list<array{label:string,rate:int,target:int|null,delta:int|null,name:string}>
     * }
     */
    private function externalInspectionDashboard(Organization $organization): array
    {
        $externalRunIds = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->whereHas('questions.opportunity', fn ($query) => $query->where('generation_source', 'external_qa_inspection'))
            ->pluck('id');

        $runs = GeoAiSearchRun::query()
            ->whereIn('id', $externalRunIds)
            ->with([
                'answers' => fn ($query) => $query->select([
                    'id',
                    'geo_ai_search_run_id',
                    'brand_mentioned',
                    'citations',
                    'source_urls',
                    'visibility_score',
                ]),
            ])
            ->withCount(['questions', 'answers'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $answers = GeoAiSearchAnswer::query()
            ->whereIn('geo_ai_search_run_id', $externalRunIds)
            ->latest()
            ->limit(300)
            ->get(['id', 'brand_mentioned', 'citations', 'source_urls', 'visibility_score', 'platform_code']);

        $totalAnswers = $answers->count();
        $brandMentionRate = $totalAnswers > 0
            ? (int) round($answers->where('brand_mentioned', true)->count() / $totalAnswers * 100)
            : 0;
        $citationRate = $totalAnswers > 0
            ? (int) round($answers->filter(fn (GeoAiSearchAnswer $answer): bool => ((array) $answer->citations) !== [] || ((array) $answer->source_urls) !== [])->count() / $totalAnswers * 100)
            : 0;
        $platformCount = $runs
            ->flatMap(fn (GeoAiSearchRun $run): array => (array) ($run->platform_codes ?? []))
            ->unique()
            ->count();
        $latestRun = $runs->first();
        $latestKeywordHitRate = $latestRun instanceof GeoAiSearchRun && $latestRun->keyword_hit_rate !== null
            ? (int) $latestRun->keyword_hit_rate
            : $brandMentionRate;
        $latestTargetKeywordHitRate = $latestRun instanceof GeoAiSearchRun
            ? $latestRun->target_keyword_hit_rate
            : null;
        $keywordHitTrend = GeoAiSearchRun::query()
            ->whereIn('id', $externalRunIds)
            ->whereIn('status', ['completed', 'partial_failed'])
            ->whereNotNull('keyword_hit_rate')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'name', 'keyword_hit_rate', 'target_keyword_hit_rate', 'keyword_hit_rate_delta', 'finished_at', 'created_at'])
            ->sortBy(fn (GeoAiSearchRun $run): string => ($run->finished_at ?? $run->created_at)?->format('Y-m-d H:i:s').'-'.str_pad((string) $run->id, 10, '0', STR_PAD_LEFT))
            ->values()
            ->map(static fn (GeoAiSearchRun $run): array => [
                'label' => ($run->finished_at ?? $run->created_at)?->format('m-d') ?? '未完成',
                'rate' => (int) $run->keyword_hit_rate,
                'target' => $run->target_keyword_hit_rate !== null ? (int) $run->target_keyword_hit_rate : null,
                'delta' => $run->keyword_hit_rate_delta !== null ? (int) $run->keyword_hit_rate_delta : null,
                'name' => (string) $run->name,
            ])
            ->all();

        return [
            'runs' => $runs,
            'latest' => $latestRun,
            'total_runs' => $externalRunIds->count(),
            'total_answers' => $totalAnswers,
            'brand_mention_rate' => $brandMentionRate,
            'keyword_hit_rate' => $latestKeywordHitRate,
            'target_keyword_hit_rate' => $latestTargetKeywordHitRate,
            'citation_rate' => $citationRate,
            'average_score' => $totalAnswers > 0 ? (int) round((float) $answers->avg('visibility_score')) : null,
            'platform_count' => $platformCount,
            'keyword_hit_trend' => $keywordHitTrend,
        ];
    }

    /**
     * @return array{answer_count:int, brand_mentions:int, brand_mention_rate:int, keyword_hit_rate:int|null, target_keyword_hit_rate:int|null, previous_keyword_hit_rate:int|null, keyword_hit_rate_delta:int|null, citation_count:int, citation_rate:int, average_score:int|null, platform_count:int}
     */
    private function searchRunEvidenceMetrics(GeoAiSearchRun $run): array
    {
        $answers = $run->questions
            ->flatMap(fn ($question) => $question->answers);
        $answerCount = $answers->count();
        $citationCount = $answers->filter(fn (GeoAiSearchAnswer $answer): bool => ((array) $answer->citations) !== [] || ((array) $answer->source_urls) !== [])->count();
        $brandMentions = $answers->where('brand_mentioned', true)->count();

        return [
            'answer_count' => $answerCount,
            'brand_mentions' => $brandMentions,
            'brand_mention_rate' => $answerCount > 0 ? (int) round($brandMentions / $answerCount * 100) : 0,
            'keyword_hit_rate' => $run->keyword_hit_rate,
            'target_keyword_hit_rate' => $run->target_keyword_hit_rate,
            'previous_keyword_hit_rate' => $run->previous_keyword_hit_rate,
            'keyword_hit_rate_delta' => $run->keyword_hit_rate_delta,
            'citation_count' => $citationCount,
            'citation_rate' => $answerCount > 0 ? (int) round($citationCount / $answerCount * 100) : 0,
            'average_score' => $answerCount > 0 ? (int) round((float) $answers->avg('visibility_score')) : null,
            'platform_count' => collect((array) $run->platform_codes)->filter()->unique()->count(),
        ];
    }

    private function unlockStaleSearchRuns(Organization $organization): void
    {
        $cutoff = now()->subSeconds($this->searchRunStaleSeconds());

        GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'running')
            ->where('updated_at', '<=', $cutoff)
            ->get()
            ->each(fn (GeoAiSearchRun $run) => $this->markSearchRunTimedOut($run));
    }

    private function markSearchRunTimedOut(GeoAiSearchRun $run): void
    {
        $run->questions()
            ->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'failed']);

        $completedQuestions = (int) $run->questions()->where('status', 'completed')->count();
        $failedQuestions = (int) $run->questions()->where('status', 'failed')->count();
        $averageScore = $run->answers()->where('status', 'succeeded')->exists()
            ? (int) round((float) $run->answers()->where('status', 'succeeded')->avg('visibility_score'))
            : 0;

        $run->forceFill([
            'status' => $completedQuestions > 0 ? 'partial_failed' : 'failed',
            'completed_questions' => $completedQuestions,
            'failed_questions' => $failedQuestions,
            'average_score' => $averageScore,
            'finished_at' => now(),
            'error_message' => '运行超时，可重试：真实平台请求超过 '.$this->searchRunStaleSeconds().' 秒或 Web 请求中断。',
        ])->save();
    }

    private function searchRunStaleSeconds(): int
    {
        return max(120, min(600, (int) config('geoflow.ai_web_workbench.timeout_seconds', 420) + 60));
    }

    private function isExternalInspectionRun(GeoAiSearchRun $run): bool
    {
        return $run->questions()
            ->whereHas('opportunity', fn ($query) => $query->where('generation_source', 'external_qa_inspection'))
            ->exists();
    }

    /**
     * 删除待运行/失败检视时，同一名称、平台和问题矩阵的重复批次一起删除，避免删掉一条后下一条同名数据顶上来。
     *
     * @return Collection<int,GeoAiSearchRun>
     */
    private function matchingSearchRunsForDeletion(GeoAiSearchRun $run): Collection
    {
        $run->loadMissing([
            'answers:id,geo_ai_search_run_id',
            'questions:id,geo_ai_search_run_id,question',
        ]);

        if (! in_array($run->status, ['pending', 'failed', 'partial_failed'], true)) {
            return collect([$run]);
        }

        $expectedPlatforms = $this->comparableSearchRunList((array) $run->platform_codes);
        $expectedQuestions = $this->comparableSearchRunList($run->questions->pluck('question')->all());

        return GeoAiSearchRun::query()
            ->where('organization_id', $run->organization_id)
            ->where('brand_profile_id', $run->brand_profile_id)
            ->where('name', $run->name)
            ->whereIn('status', ['pending', 'failed', 'partial_failed'])
            ->with([
                'answers:id,geo_ai_search_run_id',
                'questions:id,geo_ai_search_run_id,question',
            ])
            ->get()
            ->filter(function (GeoAiSearchRun $candidate) use ($expectedPlatforms, $expectedQuestions): bool {
                return $this->comparableSearchRunList((array) $candidate->platform_codes) === $expectedPlatforms
                    && $this->comparableSearchRunList($candidate->questions->pluck('question')->all()) === $expectedQuestions;
            })
            ->values();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function comparableSearchRunList(array $values): array
    {
        return collect($values)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->sort()
            ->values()
            ->all();
    }

    private function searchRunRedirectUrl(GeoAiSearchRun $run, bool $isExternalInspection): string
    {
        if ($isExternalInspection) {
            return route('admin.geo.search-runs.show', ['runId' => (int) $run->id]);
        }

        return route('admin.geo.workspace');
    }

    private function shouldRunSearchRunInBackground(GeoAiSearchRun $run, bool $isExternalInspection): bool
    {
        return $isExternalInspection
            && collect((array) $run->platform_codes)->contains(
                static fn (mixed $code): bool => (string) $code === GeoWebWorkbenchClient::PLATFORM_CODE
                    || str_starts_with((string) $code, GeoWebWorkbenchClient::PLATFORM_CODE.':')
            );
    }

    private function prepareSearchRunForBackground(GeoAiSearchRun $run): void
    {
        $run->questions()
            ->where('status', 'failed')
            ->update(['status' => 'pending']);

        $completedQuestions = (int) $run->questions()->where('status', 'completed')->count();

        $run->forceFill([
            'status' => 'running',
            'completed_questions' => $completedQuestions,
            'failed_questions' => 0,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'error_message' => null,
        ])->save();
    }

    private function launchSearchRunWorker(GeoAiSearchRun $run): void
    {
        $logPath = '/tmp/geoflow-search-run-'.$run->id.'.log';
        $command = 'nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' geo:search-run '.(int) $run->id
            .' >'.escapeshellarg($logPath).' 2>&1 &';

        $result = Process::timeout(5)
            ->env($this->backgroundSearchRunEnvironment())
            ->run(['bash', '-lc', $command]);
        if ($result->failed()) {
            throw new \RuntimeException($result->errorOutput() ?: $result->output() ?: '后台进程启动失败');
        }
    }

    /**
     * @return array<string, string>
     */
    private function backgroundSearchRunEnvironment(): array
    {
        $home = trim((string) (getenv('HOME') ?: ''));
        $paths = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $nvmNodePaths = $home !== '' ? (glob($home.'/.nvm/versions/node/*/bin') ?: []) : [];
        rsort($nvmNodePaths);
        $commandPath = app(GeoWebWorkbenchClient::class)->commandPath();

        return [
            ...$this->inheritedBackgroundEnvironment(),
            ...$this->configuredRuntimeEnvironment(),
            'HOME' => $home,
            'PATH' => collect([
                ...$nvmNodePaths,
                $home !== '' ? $home.'/.local/bin' : '',
                ...$paths,
                '/opt/homebrew/bin',
                '/usr/local/bin',
                '/usr/bin',
                '/bin',
                '/usr/sbin',
                '/sbin',
            ])->filter()->unique()->implode(PATH_SEPARATOR),
            'GEOFLOW_AI_WEB_WORKBENCH_COMMAND' => $commandPath,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function inheritedBackgroundEnvironment(): array
    {
        $allEnvironment = getenv();
        if (! is_array($allEnvironment)) {
            return [];
        }

        $allowedKeys = ['DATABASE_URL'];
        $allowedPrefixes = [
            'AI_',
            'ANTHROPIC_',
            'APP_',
            'CACHE_',
            'DB_',
            'DEEPSEEK_',
            'GEOFLOW_',
            'LOG_',
            'OPENAI_',
            'QUEUE_',
            'REDIS_',
            'SESSION_',
            'YIXIAOER_',
        ];

        $environment = [];
        foreach ($allEnvironment as $key => $value) {
            if (! is_string($key) || (! in_array($key, $allowedKeys, true) && ! $this->startsWithAny($key, $allowedPrefixes))) {
                continue;
            }

            $environment[$key] = $value;
        }

        return $this->normalizeBackgroundEnvironment($environment);
    }

    /**
     * @return array<string, string>
     */
    private function configuredRuntimeEnvironment(): array
    {
        $connection = (string) config('database.default', getenv('DB_CONNECTION') ?: 'sqlite');
        $connectionConfig = (array) config("database.connections.{$connection}", []);
        $environment = [
            'APP_ENV' => (string) config('app.env', app()->environment()),
            'APP_DEBUG' => config('app.debug') ? 'true' : 'false',
            'APP_KEY' => (string) config('app.key', ''),
            'APP_URL' => (string) config('app.url', ''),
            'DB_CONNECTION' => $connection,
        ];

        $databaseKeys = [
            'url' => 'DB_URL',
            'host' => 'DB_HOST',
            'port' => 'DB_PORT',
            'database' => 'DB_DATABASE',
            'username' => 'DB_USERNAME',
            'password' => 'DB_PASSWORD',
        ];

        foreach ($databaseKeys as $configKey => $environmentKey) {
            if (array_key_exists($configKey, $connectionConfig)) {
                $environment[$environmentKey] = $connectionConfig[$configKey];
            }
        }

        foreach (['DB_URL', 'DATABASE_URL'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = $value;
            }
        }

        return $this->normalizeBackgroundEnvironment($environment);
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return array<string, string>
     */
    private function normalizeBackgroundEnvironment(array $environment): array
    {
        $normalized = [];
        foreach ($environment as $key => $value) {
            if (! is_string($key) || $value === null) {
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';

                continue;
            }

            if (is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
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
     * @return array{drafts: int, converted: int, audits: int, retests: int, conversion_label: string}
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
        $retests = GeoPublishRetest::query()
            ->where('organization_id', $organization->id)
            ->count();

        return [
            'drafts' => $drafts,
            'converted' => $converted,
            'audits' => $audits,
            'retests' => $retests,
            'conversion_label' => $converted.' / '.$drafts,
        ];
    }

    /**
     * @return array{
     *     drafts:Collection<int,GeoArticleDraft>,
     *     articles:Collection<int,Article>,
     *     stats:array{drafts:int,converted:int,articles:int,publish_records:int,pending_review:int,retests:int}
     * }
     */
    private function geoArticleWorkspace(Organization $organization): array
    {
        $drafts = GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->with([
                'article:id,title,slug,status,review_status,published_at,metadata',
                'writingTask:id,title,brief,status',
                'publishRecords' => fn ($query) => $query->latest(),
            ])
            ->latest()
            ->limit(12)
            ->get();

        $articleIds = GeoArticleDraft::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('article_id')
            ->latest()
            ->limit(30)
            ->pluck('article_id')
            ->filter()
            ->values();

        $articles = Article::query()
            ->with(['geoArticleDrafts' => fn ($query) => $query->where('organization_id', $organization->id)->latest()])
            ->whereIn('id', $articleIds)
            ->latest()
            ->limit(12)
            ->get();

        return [
            'drafts' => $drafts,
            'articles' => $articles,
            'stats' => [
                'drafts' => GeoArticleDraft::query()->where('organization_id', $organization->id)->count(),
                'converted' => GeoArticleDraft::query()->where('organization_id', $organization->id)->where('status', 'converted')->count(),
                'articles' => $articleIds->count(),
                'publish_records' => GeoPublishRecord::query()
                    ->whereHas('articleDraft', fn ($query) => $query->where('organization_id', $organization->id))
                    ->count(),
                'pending_review' => Article::query()->whereIn('id', $articleIds)->where('review_status', 'pending')->count(),
                'retests' => GeoPublishRetest::query()->where('organization_id', $organization->id)->count(),
            ],
        ];
    }

    /**
     * @return array{
     *     stats:array{keyword_libraries:int,title_libraries:int,image_libraries:int,knowledge_bases:int,authors:int,categories:int,geo_opportunities:int,geo_search_runs:int,geo_citation_sources:int},
     *     keyword_libraries:Collection<int,KeywordLibrary>,
     *     title_libraries:Collection<int,TitleLibrary>,
     *     image_libraries:Collection<int,ImageLibrary>,
     *     knowledge_bases:Collection<int,KnowledgeBase>,
     *     geo_opportunities:Collection<int,GeoKeywordOpportunity>,
     *     geo_search_runs:Collection<int,GeoAiSearchRun>,
     *     geo_citation_sources:Collection<int,GeoCitationSource>
     * }
     */
    private function geoMaterialWorkspace(Organization $organization): array
    {
        return [
            'stats' => [
                'keyword_libraries' => KeywordLibrary::query()->count(),
                'title_libraries' => TitleLibrary::query()->count(),
                'image_libraries' => ImageLibrary::query()->count(),
                'knowledge_bases' => KnowledgeBase::query()->count(),
                'authors' => Author::query()->count(),
                'categories' => Category::query()->count(),
                'geo_opportunities' => GeoKeywordOpportunity::query()->where('organization_id', $organization->id)->count(),
                'geo_search_runs' => GeoAiSearchRun::query()->where('organization_id', $organization->id)->count(),
                'geo_citation_sources' => GeoCitationSource::query()->where('organization_id', $organization->id)->count(),
            ],
            'keyword_libraries' => KeywordLibrary::query()->latest()->limit(6)->get(),
            'title_libraries' => TitleLibrary::query()->latest()->limit(6)->get(),
            'image_libraries' => ImageLibrary::query()->latest()->limit(6)->get(),
            'knowledge_bases' => KnowledgeBase::query()->latest()->limit(6)->get(),
            'geo_opportunities' => GeoKeywordOpportunity::query()
                ->where('organization_id', $organization->id)
                ->orderByDesc('opportunity_score')
                ->latest()
                ->limit(8)
                ->get(),
            'geo_search_runs' => GeoAiSearchRun::query()
                ->where('organization_id', $organization->id)
                ->latest()
                ->limit(6)
                ->get(),
            'geo_citation_sources' => GeoCitationSource::query()
                ->where('organization_id', $organization->id)
                ->with('latestPageSnapshot.latestScore')
                ->orderByDesc('last_seen_at')
                ->latest()
                ->limit(8)
                ->get(),
        ];
    }
}
