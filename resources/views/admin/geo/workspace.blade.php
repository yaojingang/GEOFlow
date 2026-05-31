@extends('admin.layouts.app')

@php
    $aliasesText = implode("\n", (array) ($brandProfile?->aliases ?? []));
    $extendedProfile = (array) ($brandProfile?->extended_profile ?? []);
    $keywordTypeLabels = [
        'industry' => '行业词',
        'brand' => '品牌词',
        'competitor' => '竞品词',
        'question' => '问题词',
    ];
    $statusLabels = [
        'pending' => '待执行',
        'running' => '执行中',
        'completed' => '已完成',
        'partial_failed' => '部分失败',
        'failed' => '失败',
    ];
    $searchRunStatusLabels = [
        'pending' => '待运行',
        'running' => '运行中',
        'completed' => '已完成',
        'partial_failed' => '部分失败',
        'failed' => '失败',
    ];
    $citationSourceStatusLabels = [
        'pending_crawl' => '待采集',
        'crawled' => '已采集',
        'crawl_failed' => '采集失败',
    ];
    $trendMetrics = $trendMetrics ?? [
        'latest_score' => null,
        'average_score' => null,
        'delta' => null,
        'reports_count' => 0,
    ];
    $pipelineMetrics = $pipelineMetrics ?? [
        'drafts' => 0,
        'converted' => 0,
        'audits' => 0,
        'retests' => 0,
        'conversion_label' => '0 / 0',
    ];
    $geoArticleWorkspace = $geoArticleWorkspace ?? [
        'drafts' => collect(),
        'articles' => collect(),
        'stats' => [
            'drafts' => 0,
            'converted' => 0,
            'articles' => 0,
            'publish_records' => 0,
            'pending_review' => 0,
            'retests' => 0,
        ],
    ];
    $geoMaterialWorkspace = $geoMaterialWorkspace ?? [
        'stats' => [
            'keyword_libraries' => 0,
            'title_libraries' => 0,
            'image_libraries' => 0,
            'knowledge_bases' => 0,
            'authors' => 0,
            'categories' => 0,
            'geo_opportunities' => 0,
            'geo_search_runs' => 0,
            'geo_citation_sources' => 0,
        ],
        'keyword_libraries' => collect(),
        'title_libraries' => collect(),
        'image_libraries' => collect(),
        'knowledge_bases' => collect(),
        'geo_opportunities' => collect(),
        'geo_search_runs' => collect(),
        'geo_citation_sources' => collect(),
    ];
    $geoDraftsForWorkspace = collect($geoArticleWorkspace['drafts'] ?? []);
    $geoArticlesForWorkspace = collect($geoArticleWorkspace['articles'] ?? []);
    $geoArticleStats = (array) ($geoArticleWorkspace['stats'] ?? []);
    $geoMaterialStats = (array) ($geoMaterialWorkspace['stats'] ?? []);
    $geoOpportunityMaterials = collect($geoMaterialWorkspace['geo_opportunities'] ?? []);
    $geoSearchRunMaterials = collect($geoMaterialWorkspace['geo_search_runs'] ?? []);
    $geoCitationSourceMaterials = collect($geoMaterialWorkspace['geo_citation_sources'] ?? []);
    $yixiaoerContentOverview = $yixiaoerContentOverview ?? [
        'configured' => false,
        'error' => '未配置 YIXIAOER_API_KEY',
        'updated_at' => null,
        'total_size' => 0,
        'stats' => [
            'works' => 0,
            'play' => 0,
            'read' => 0,
            'recommend' => 0,
            'likes' => 0,
            'comments' => 0,
            'collects' => 0,
            'shares' => 0,
            'engagement' => 0,
        ],
        'items' => [],
        'filters' => [
            'keyword' => '',
            'platform' => '',
        ],
        'platform_filters' => [],
        'platform_groups' => [],
    ];
    $yixiaoerContentStats = (array) ($yixiaoerContentOverview['stats'] ?? []);
    $yixiaoerContentItems = collect($yixiaoerContentOverview['items'] ?? []);
    $yixiaoerPlatformFilters = collect($yixiaoerContentOverview['platform_filters'] ?? []);
    $yixiaoerPlatformGroups = collect($yixiaoerContentOverview['platform_groups'] ?? []);
    $yixiaoerPublishedKeyword = (string) data_get($yixiaoerContentOverview, 'filters.keyword', request('published_content_keyword', ''));
    $yixiaoerPublishedPlatform = (string) data_get($yixiaoerContentOverview, 'filters.platform', request('published_content_platform', ''));
    $publishedContentFilterUrl = static function (?string $platform = null) use ($yixiaoerPublishedKeyword): string {
        $query = ['published_content_overview' => 1];
        if ($yixiaoerPublishedKeyword !== '') {
            $query['published_content_keyword'] = $yixiaoerPublishedKeyword;
        }
        if ($platform !== null && $platform !== '') {
            $query['published_content_platform'] = $platform;
        }

        return route('admin.geo.workspace', $query).'#published-content';
    };
    $formatYixiaoerMetric = static fn (mixed $value): string => number_format((int) $value);
    $geoSourceLabel = static function (string $source): string {
        return match ($source) {
            'geo_reference_imitation', 'reference_imitation' => 'GEO 引用仿写',
            'geo_reference_content', 'reference_content' => 'GEO 引用资料',
            'geo_report' => 'GEO 诊断报告',
            default => str_starts_with($source, 'geo_') ? 'GEO 生成内容' : 'GEO 内容',
        };
    };
    $publishRecordStatusLabels = [
        'pending' => '待提交',
        'submitted' => '已提交',
        'published' => '已发布',
        'failed' => '失败',
    ];
    $realAiModels = $realAiModels ?? collect();
    $opportunities = $opportunities ?? collect();
    $searchRuns = $searchRuns ?? collect();
    $citationSources = $citationSources ?? collect();
    $externalInspection = $externalInspection ?? [
        'runs' => collect(),
        'latest' => null,
        'total_runs' => 0,
        'total_answers' => 0,
        'brand_mention_rate' => 0,
        'keyword_hit_rate' => 0,
        'target_keyword_hit_rate' => null,
        'citation_rate' => 0,
        'average_score' => null,
        'platform_count' => 0,
        'keyword_hit_trend' => [],
    ];
    $externalInspectionRuns = collect($externalInspection['runs'] ?? []);
    $externalInspectionLatest = $externalInspection['latest'] ?? null;
    $externalKeywordHitTrend = collect($externalInspection['keyword_hit_trend'] ?? []);
    $latestOptimizationDirections = collect($externalInspectionLatest?->optimization_directions ?? [])->filter();
    $externalQuestionDefaults = trim(implode("\n", array_filter([
        trim((string) ($brandProfile?->brand_name ?? '')) !== '' ? trim((string) ($brandProfile?->brand_name ?? '')).'怎么样' : '',
        trim((string) ($brandProfile?->service_area ?? '')) !== '' ? trim((string) ($brandProfile?->service_area ?? '')).'全屋定制哪家靠谱' : '本地全屋定制哪家靠谱',
        '全屋定制怎么避免报价和售后踩坑',
        '衣柜橱柜定制推荐谁',
        '本地全屋定制和成品家具怎么选',
    ])));
    $brandDisplayName = trim((string) ($brandProfile?->brand_name ?? $organization->name ?? '本企业')) ?: '本企业';
    $serviceAreaName = trim((string) ($brandProfile?->service_area ?? '本地')) ?: '本地';
    $productName = trim((string) ($brandProfile?->products ?? '全屋定制')) ?: '全屋定制';
    $inspectionPresets = collect([
        [
            'name' => '品牌可见度检视',
            'description' => '先看 AI 是否知道企业、是否主动提到品牌。',
            'target' => 75,
            'questions' => [
                $brandDisplayName.'怎么样',
                $brandDisplayName.'靠谱吗',
                $brandDisplayName.'有什么优势',
                $brandDisplayName.'适合哪些客户',
            ],
            'icon' => 'radar',
        ],
        [
            'name' => '本地获客检视',
            'description' => '模拟客户找服务商时会问的问题。',
            'target' => 70,
            'questions' => [
                $serviceAreaName.$productName.'哪家靠谱',
                $serviceAreaName.$productName.'推荐谁',
                $serviceAreaName.$productName.'怎么选',
                $serviceAreaName.$productName.'价格一般多少',
            ],
            'icon' => 'map-pin',
        ],
        [
            'name' => '避坑信任检视',
            'description' => '检查 AI 是否能讲清客户顾虑和信任理由。',
            'target' => 68,
            'questions' => [
                $productName.'怎么避免报价踩坑',
                $productName.'售后需要注意什么',
                $productName.'合同要看哪些细节',
                $productName.'环保和板材怎么判断',
            ],
            'icon' => 'shield-check',
        ],
        [
            'name' => '竞品对比检视',
            'description' => '看 AI 会不会推荐竞品，以及推荐逻辑是什么。',
            'target' => 65,
            'questions' => [
                $serviceAreaName.$productName.'有哪些品牌',
                $brandDisplayName.'和本地其他商家怎么比',
                $serviceAreaName.$productName.'口碑好的商家有哪些',
                $serviceAreaName.$productName.'客户评价看哪些',
            ],
            'icon' => 'scale',
        ],
    ])->map(static function (array $preset): array {
        $preset['questions_text'] = implode("\n", array_values(array_filter($preset['questions'])));

        return $preset;
    })->all();
    $manualExpansionDefaults = [
        'area_prefixes' => trim((string) ($brandProfile?->service_area ?? '')),
        'modifiers' => "靠谱的\n口碑好的\n专业的",
        'core_terms' => trim((string) ($brandProfile?->products ?? '全屋定制')),
        'entity_terms' => "品牌\n厂家\n公司",
        'recommend_terms' => '推荐',
        'question_terms' => "哪家好\n有哪些",
        'limit' => 80,
    ];
    $manualExpansionPatterns = [
        'C+D' => 'C+D',
        'A+C+D' => 'A+C+D',
        'B+C+D' => 'B+C+D',
        'A+B+C+D' => 'A+B+C+D',
        'C+D+E' => 'C+D+E',
        'C+D+F' => 'C+D+F',
        'A+C+D+E' => 'A+C+D+E',
        'A+B+C+D+E' => 'A+B+C+D+E',
        'A+B+C+D+F' => 'A+B+C+D+F',
    ];
    $selectedManualExpansionPatterns = old('combination_patterns', [
        'C+D',
        'A+C+D',
        'B+C+D',
        'A+B+C+D',
        'C+D+E',
        'C+D+F',
    ]);
    $trendDelta = $trendMetrics['delta'];
    $trendDeltaLabel = $trendDelta === null
        ? '暂无对比'
        : '较上次 '.($trendDelta >= 0 ? '+'.$trendDelta : (string) $trendDelta);
    $workspaceTabs = [
        [
            'id' => 'overview',
            'label' => '总览',
            'description' => '核心指标',
            'icon' => 'layout-dashboard',
        ],
        [
            'id' => 'external-qa',
            'label' => '检视任务',
            'description' => '问题矩阵、外部问答证据、复测入口',
            'icon' => 'messages-square',
        ],
        [
            'id' => 'search',
            'label' => '引用来源',
            'description' => '机会词、AI 搜索、引用来源',
            'icon' => 'search',
        ],
        [
            'id' => 'setup',
            'label' => '企业资料',
            'description' => '品牌资料、关键词、诊断创建',
            'icon' => 'sliders-horizontal',
        ],
        [
            'id' => 'articles',
            'label' => '内容资产',
            'description' => 'GEO 草稿、正式文章和公众号草稿',
            'icon' => 'newspaper',
        ],
        [
            'id' => 'tasks',
            'label' => '发布复测',
            'description' => '诊断任务与报告入口',
            'icon' => 'file-check-2',
        ],
        [
            'id' => 'materials',
            'label' => '素材库',
            'description' => '关键词、标题、图片和知识库素材',
            'icon' => 'folder-kanban',
        ],
        [
            'id' => 'ai-platforms',
            'label' => '模型接入',
            'description' => '真实搜索软件、模型、备用平台',
            'icon' => 'bot',
        ],
    ];
    $brandReadinessItems = [
        ['key' => 'brand_name', 'label' => '品牌名称', 'filled' => trim((string) ($brandProfile?->brand_name ?? '')) !== ''],
        ['key' => 'aliases', 'label' => '品牌别名', 'filled' => count((array) ($brandProfile?->aliases ?? [])) > 0],
        ['key' => 'products', 'label' => '产品服务', 'filled' => trim((string) ($brandProfile?->products ?? '')) !== ''],
        ['key' => 'advantages', 'label' => '核心优势', 'filled' => trim((string) ($brandProfile?->advantages ?? '')) !== ''],
        ['key' => 'cases', 'label' => '案例素材', 'filled' => trim((string) ($brandProfile?->cases ?? '')) !== ''],
        ['key' => 'pain_points', 'label' => '客户痛点', 'filled' => trim((string) ($brandProfile?->pain_points ?? '')) !== ''],
        ['key' => 'service_area', 'label' => '服务区域', 'filled' => trim((string) ($brandProfile?->service_area ?? '')) !== ''],
        ['key' => 'short_name', 'label' => '品牌简称', 'filled' => trim((string) ($extendedProfile['short_name'] ?? '')) !== ''],
        ['key' => 'writing_directions', 'label' => '写作方向', 'filled' => trim((string) ($extendedProfile['writing_directions'] ?? '')) !== ''],
        ['key' => 'copy_types', 'label' => '文案类型', 'filled' => count((array) ($extendedProfile['copy_types'] ?? [])) > 0],
        ['key' => 'product_features', 'label' => '产品特点', 'filled' => count((array) ($extendedProfile['product_features'] ?? [])) > 0],
        ['key' => 'trust_proofs', 'label' => '信任背书', 'filled' => count((array) ($extendedProfile['trust_proofs'] ?? [])) > 0],
        ['key' => 'promotion_regions', 'label' => '推广区域', 'filled' => count((array) ($extendedProfile['promotion_regions'] ?? [])) > 0],
        ['key' => 'forbidden_claims', 'label' => '禁用表达', 'filled' => count((array) ($extendedProfile['forbidden_claims'] ?? [])) > 0],
    ];
    $brandReadinessFilled = collect($brandReadinessItems)->where('filled', true)->count();
    $brandReadinessTotal = count($brandReadinessItems);
    $brandReadinessPercent = $brandReadinessTotal > 0 ? (int) round($brandReadinessFilled / $brandReadinessTotal * 100) : 0;
    $missingBrandReadinessItems = collect($brandReadinessItems)->where('filled', false)->values();
    $keywordStats = collect($keywordTypeLabels)
        ->map(fn (string $label, string $type): array => [
            'type' => $type,
            'label' => $label,
            'count' => $keywords->where('type', $type)->count(),
        ])
        ->values();
    $realSearchPlatforms = $platforms->where('api_mode', 'web_workbench')->values();
    $mockSearchPlatforms = $platforms->where('api_mode', 'mock')->values();
    $activePlatformCount = $realSearchPlatforms->count() + $realAiModels->count();
    $fullDiagnosisCost = $keywords->count() * max(1, $realSearchPlatforms->sum('cost_per_query') + $realAiModels->count());
    $diagnosisReady = $brandProfile !== null && $keywords->isNotEmpty() && $activePlatformCount > 0;
    $webWorkbenchPlatform = $platforms->firstWhere('code', 'ai_web_workbench');
    $webWorkbenchReady = $webWorkbenchPlatform !== null && (string) $webWorkbenchPlatform->status === 'active';
    $webWorkbenchCommand = trim((string) ($webWorkbenchCommand ?? config('geoflow.ai_web_workbench.command', ''))) ?: 'ai-web-workbench';
    $webWorkbenchStatus = $webWorkbenchStatus ?? ['ok' => false, 'tasks' => []];
    $webWorkbenchTasks = collect((array) ($webWorkbenchStatus['tasks'] ?? []));
    $webWorkbenchPlatformStatuses = collect((array) ($webWorkbenchStatus['platforms'] ?? []));
    $materialReadyCount = collect($geoMaterialStats)->sum(fn ($value): int => (int) $value);
    $geoWorkflowSteps = [
        [
            'key' => 'brand',
            'number' => '01',
            'label' => '品牌资料',
            'description' => '品牌事实、写作规则、诊断口径',
            'href' => '#setup',
            'metric' => $brandProfile ? '已保存' : '待补齐',
            'done' => $brandProfile !== null,
            'icon' => 'database',
        ],
        [
            'key' => 'opportunities',
            'number' => '02',
            'label' => '机会搜索',
            'description' => '关键词机会、ABCDEF 拓词、搜索批次',
            'href' => '#search',
            'metric' => $opportunities->count().' 个机会',
            'done' => $opportunities->isNotEmpty() || $keywords->isNotEmpty(),
            'icon' => 'search',
        ],
        [
            'key' => 'ai-search',
            'number' => '03',
            'label' => '真实AI搜索',
            'description' => '本机搜索软件与真实模型',
            'href' => '#ai-platforms',
            'metric' => $activePlatformCount.' 个平台',
            'done' => $activePlatformCount > 0 || $searchRuns->isNotEmpty(),
            'icon' => 'bot',
        ],
        [
            'key' => 'citations',
            'number' => '04',
            'label' => '引用源分析',
            'description' => '采集高分页面并解释被引用原因',
            'href' => route('admin.geo.citation-sources.index'),
            'metric' => $citationSources->count().' 个来源',
            'done' => $citationSources->isNotEmpty(),
            'icon' => 'link',
        ],
        [
            'key' => 'drafts',
            'number' => '05',
            'label' => '仿写草稿',
            'description' => '引用源仿写、正文排版、可发布化',
            'href' => '#articles',
            'metric' => (int) ($geoArticleStats['drafts'] ?? 0).' 篇草稿',
            'done' => (int) ($geoArticleStats['drafts'] ?? 0) > 0,
            'icon' => 'file-pen-line',
        ],
        [
            'key' => 'materials',
            'number' => '06',
            'label' => '素材补齐',
            'description' => '关键词、标题、图片、知识库、作者栏目',
            'href' => '#materials',
            'metric' => $materialReadyCount.' 项素材',
            'done' => $materialReadyCount > 0,
            'icon' => 'folder-kanban',
        ],
        [
            'key' => 'articles',
            'number' => '07',
            'label' => '正式文章',
            'description' => '草稿转正式文章并进入审核',
            'href' => route('admin.articles.index'),
            'metric' => (int) ($geoArticleStats['articles'] ?? 0).' 篇文章',
            'done' => (int) ($geoArticleStats['articles'] ?? 0) > 0,
            'icon' => 'newspaper',
        ],
        [
            'key' => 'publish',
            'number' => '08',
            'label' => '公众号交接',
            'description' => '公众号草稿、蚁小二交接、登录检查',
            'href' => '#articles',
            'metric' => (int) ($geoArticleStats['publish_records'] ?? 0).' 条记录',
            'done' => (int) ($geoArticleStats['publish_records'] ?? 0) > 0,
            'icon' => 'send',
        ],
        [
            'key' => 'retest',
            'number' => '09',
            'label' => '复测报告',
            'description' => '发布后复测并沉淀下一轮任务',
            'href' => '#tasks',
            'metric' => (int) ($geoArticleStats['retests'] ?? $pipelineMetrics['retests'] ?? 0).' 条复测',
            'done' => (int) ($geoArticleStats['retests'] ?? $pipelineMetrics['retests'] ?? 0) > 0,
            'icon' => 'radar',
        ],
    ];
    $geoWorkflowReadyCount = collect($geoWorkflowSteps)->where('done', true)->count();
    $geoWorkflowTotal = count($geoWorkflowSteps);
    $geoOperationsLinks = [
        [
            'label' => '任务管理',
            'description' => '承接 GEO 诊断、写作和分发动作',
            'href' => route('admin.tasks.index'),
            'icon' => 'list-todo',
        ],
        [
            'label' => 'AI配置器',
            'description' => '模型、提示词和连接测试',
            'href' => route('admin.ai.configurator'),
            'icon' => 'sliders-horizontal',
        ],
        [
            'label' => '站点设置',
            'description' => '站点基础信息、主题与敏感词',
            'href' => route('admin.site-settings.index'),
            'icon' => 'settings',
        ],
    ];
    $geoFlowCards = [
        [
            'key' => 'brand-profile',
            'label' => '企业业务身份证',
            'description' => '品牌、产品、案例、服务区域和禁用表达先统一。',
            'href' => '#setup',
            'metric' => $brandReadinessPercent.'%',
            'icon' => 'building-2',
        ],
        [
            'key' => 'ai-visibility',
            'label' => 'AI 里搜得到你吗',
            'description' => '用真实问题在多平台 AI 中检视品牌出现率。',
            'href' => '#external-qa',
            'metric' => (int) ($externalInspection['total_runs'] ?? 0).' 次',
            'icon' => 'messages-square',
        ],
        [
            'key' => 'citation-sources',
            'label' => 'AI 引用了哪些网页',
            'description' => '把引用源、竞品网页和可仿写素材集中处理。',
            'href' => '#search',
            'metric' => $citationSources->count().' 条',
            'icon' => 'link-2',
        ],
        [
            'key' => 'content-assets',
            'label' => '内容资产',
            'description' => '草稿、文章、素材库和公众号发布包一处跟进。',
            'href' => '#articles',
            'metric' => (int) ($geoArticleStats['articles'] ?? 0).' 篇',
            'icon' => 'newspaper',
        ],
        [
            'key' => 'publish-retest',
            'label' => '发布与复测',
            'description' => '发布后复测 AI 回答变化，沉淀下一轮任务。',
            'href' => '#tasks',
            'metric' => (int) ($geoArticleStats['retests'] ?? $pipelineMetrics['retests'] ?? 0).' 条',
            'icon' => 'repeat-2',
        ],
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <section data-geo-flow-shell class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-0 xl:grid-cols-[330px_minmax(0,1fr)]">
                <div class="border-b border-slate-100 bg-white px-5 py-5 sm:px-6 xl:border-b-0 xl:border-r">
                    <p class="inline-flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                        <i data-lucide="radar" class="h-3.5 w-3.5"></i>
                        GEO 工作台
                    </p>
                    <h1 class="mt-4 text-2xl font-semibold text-slate-950">AI 可见度工作台</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $organization->name }} · 按“资料、检视、引用、内容、复测”推进获客闭环。</p>
                    <div class="mt-5 grid grid-cols-2 gap-2 text-sm">
                        <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600">
                            <span class="block text-xs text-slate-400">品牌完整度</span>
                            <span class="mt-1 block font-semibold text-slate-950">{{ $brandReadinessPercent }}%</span>
                        </span>
                        <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600">
                            <span class="block text-xs text-slate-400">链路推进</span>
                            <span class="mt-1 block font-semibold text-slate-950">{{ $geoWorkflowReadyCount }} / {{ $geoWorkflowTotal }}</span>
                        </span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="#setup" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700">
                            <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                            下一步
                        </a>
                        <a href="{{ route('admin.operation-guide') }}" class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            <i data-lucide="circle-help" class="h-3.5 w-3.5"></i>
                            查看说明
                        </a>
                    </div>
                </div>
                <div data-geo-flow-progress class="bg-slate-50 px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-sm font-semibold text-slate-950">GEO 获客主线</div>
                            <div class="mt-1 text-xs text-slate-500">新用户只看这 5 步，不用先理解所有菜单。</div>
                        </div>
                        <span class="inline-flex w-fit items-center gap-2 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-slate-200">
                            <i data-lucide="route" class="h-3.5 w-3.5 text-blue-600"></i>
                            {{ $geoWorkflowReadyCount }} / {{ $geoWorkflowTotal }} 已推进
                        </span>
                    </div>
                    <div class="mt-4 grid gap-2 lg:grid-cols-5">
                    @foreach($geoFlowCards as $index => $card)
                        <a href="{{ $card['href'] }}" data-geo-flow-step="{{ $card['key'] }}" class="group flex min-h-[118px] flex-col rounded-md border border-slate-200 bg-white px-3 py-3 transition hover:border-blue-200 hover:bg-blue-50/60">
                            <span class="flex items-start justify-between gap-2">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-600 group-hover:bg-blue-100 group-hover:text-blue-700">
                                    <i data-lucide="{{ $card['icon'] }}" class="h-4 w-4"></i>
                                </span>
                                <span class="text-xs font-medium text-slate-400">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            </span>
                            <span class="mt-3">
                                <span class="block text-sm font-semibold text-slate-950">{{ $card['label'] }}</span>
                                <span class="mt-1 block min-h-10 text-xs leading-5 text-slate-500">{{ $card['description'] }}</span>
                                <span class="mt-2 inline-flex rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">{{ $card['metric'] }}</span>
                            </span>
                        </a>
                    @endforeach
                    </div>
                </div>
            </div>
        </section>

        <nav data-geo-tabs-compact class="overflow-x-auto border-b border-slate-200 bg-transparent px-1" aria-label="GEO 工作台二级导航">
            <div class="flex min-w-max gap-4">
                @foreach ($workspaceTabs as $tab)
                    <button type="button" data-geo-tab-target="{{ $tab['id'] }}" title="{{ $tab['description'] }}" class="geo-workspace-tab inline-flex h-10 items-center gap-1.5 border-b-2 border-transparent px-1 text-sm font-medium text-gray-600 transition hover:border-blue-200 hover:text-blue-700">
                        <i data-lucide="{{ $tab['icon'] }}" class="h-4 w-4 text-slate-400"></i>
                        <span class="whitespace-nowrap">{{ $tab['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </nav>

        <div data-geo-tab-panel="overview" class="space-y-4">
        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">品牌资料</span>
                    <i data-lucide="building-2" class="h-5 w-5 text-blue-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $brandProfile ? 1 : 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">关键词</span>
                    <i data-lucide="list-filter" class="h-5 w-5 text-emerald-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $keywords->count() }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">诊断任务</span>
                    <i data-lucide="radar" class="h-5 w-5 text-indigo-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $tasks->count() }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">真实 AI 平台</span>
                    <i data-lucide="sparkles" class="h-5 w-5 text-purple-500"></i>
                </div>
                <div class="mt-3 text-2xl font-semibold text-gray-900">{{ $activePlatformCount }}</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">GEO 趋势</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ (int) $trendMetrics['reports_count'] }} 份报告纳入统计</p>
                    </div>
                    <i data-lucide="trending-up" class="h-5 w-5 text-emerald-500"></i>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <div class="text-sm text-gray-500">最新得分</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $trendMetrics['latest_score'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">平均得分</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $trendMetrics['average_score'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">趋势变化</div>
                        <div class="mt-2 text-sm font-medium {{ ($trendDelta ?? 0) >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $trendDeltaLabel }}</div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">内容闭环</h2>
                        <p class="mt-1 text-sm text-gray-500">从诊断报告到草稿、正式文章和发布前检查</p>
                    </div>
                    <i data-lucide="route" class="h-5 w-5 text-blue-500"></i>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <div class="text-sm text-gray-500">文章草稿</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $pipelineMetrics['drafts'] }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">已转文章</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $pipelineMetrics['conversion_label'] }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">GEO 检查</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) $pipelineMetrics['audits'] }}</div>
                    </div>
                </div>
            </section>
        </div>

        <section id="published-content" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">发布作品数据</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @if (($yixiaoerContentOverview['updated_at'] ?? null) !== null)
                            更新时间 {{ $yixiaoerContentOverview['updated_at'] }}
                        @elseif (! ($yixiaoerContentOverview['configured'] ?? false))
                            蚁小二数据接口未配置
                        @else
                            暂无同步时间
                        @endif
                    </p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <form method="GET" action="{{ route('admin.geo.workspace') }}#published-content" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <input type="hidden" name="published_content_overview" value="1">
                        <div>
                            <label for="published_content_keyword" class="text-xs font-medium text-gray-500">关键词筛选</label>
                            <div class="relative mt-1">
                                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"></i>
                                <input
                                    id="published_content_keyword"
                                    name="published_content_keyword"
                                    value="{{ $yixiaoerPublishedKeyword }}"
                                    type="search"
                                    placeholder="搜标题、账号、平台"
                                    class="w-full rounded-lg border border-gray-300 py-2 pl-9 pr-3 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:w-64"
                                >
                            </div>
                        </div>
                        @if ($yixiaoerPublishedPlatform !== '')
                            <input type="hidden" name="published_content_platform" value="{{ $yixiaoerPublishedPlatform }}">
                        @endif
                        <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-800">
                            <i data-lucide="filter" class="h-4 w-4"></i>
                            加载 / 筛选
                        </button>
                        @if ($yixiaoerPublishedKeyword !== '' || $yixiaoerPublishedPlatform !== '')
                            <a href="{{ route('admin.geo.workspace', ['published_content_overview' => 1]) }}#published-content" class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">清空</a>
                        @endif
                    </form>
                    <span class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-blue-50 px-3 text-sm font-medium text-blue-700">
                        <i data-lucide="bar-chart-3" class="h-4 w-4"></i>
                        {{ $formatYixiaoerMetric($yixiaoerContentStats['works'] ?? 0) }} / {{ (int) ($yixiaoerContentOverview['total_size'] ?? 0) }} 条作品
                    </span>
                </div>
            </div>

            @if ($yixiaoerPlatformFilters->isNotEmpty())
                <div class="mt-4">
                    <div class="mb-2 text-xs font-medium text-gray-500">平台筛选</div>
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        <a
                            href="{{ $publishedContentFilterUrl(null) }}"
                            class="inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium {{ $yixiaoerPublishedPlatform === '' ? 'bg-gray-900 text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50' }}"
                            @if ($yixiaoerPublishedPlatform === '') aria-current="true" @endif
                        >
                            全部平台
                            <span class="{{ $yixiaoerPublishedPlatform === '' ? 'text-gray-200' : 'text-gray-400' }}">{{ $formatYixiaoerMetric($yixiaoerPlatformFilters->sum('works')) }}</span>
                        </a>
                        @foreach ($yixiaoerPlatformFilters as $platformFilter)
                            @php
                                $platformName = (string) ($platformFilter['platform'] ?? '');
                                $isCurrentPlatform = $platformName !== '' && $platformName === $yixiaoerPublishedPlatform;
                            @endphp
                            @if ($platformName !== '')
                                <a
                                    href="{{ $publishedContentFilterUrl($platformName) }}"
                                    class="inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium {{ $isCurrentPlatform ? 'bg-gray-900 text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50' }}"
                                    @if ($isCurrentPlatform) aria-current="true" @endif
                                >
                                    {{ $platformName }}
                                    <span class="{{ $isCurrentPlatform ? 'text-gray-200' : 'text-gray-400' }}">{{ $formatYixiaoerMetric($platformFilter['works'] ?? 0) }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if (($yixiaoerContentOverview['error'] ?? null) !== null)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $yixiaoerContentOverview['error'] }}
                </div>
            @else
                <div class="mt-5 grid gap-3 md:grid-cols-4">
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">筛选作品</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ $formatYixiaoerMetric($yixiaoerContentStats['works'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">播放 / 阅读</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ $formatYixiaoerMetric($yixiaoerContentStats['play'] ?? 0) }} / {{ $formatYixiaoerMetric($yixiaoerContentStats['read'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">点赞 / 评论</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ $formatYixiaoerMetric($yixiaoerContentStats['likes'] ?? 0) }} / {{ $formatYixiaoerMetric($yixiaoerContentStats['comments'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">收藏 / 分享</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ $formatYixiaoerMetric($yixiaoerContentStats['collects'] ?? 0) }} / {{ $formatYixiaoerMetric($yixiaoerContentStats['shares'] ?? 0) }}</div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">按平台与账号查看</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                先按平台归类，再展开到账号，便于看每个账号的作品表现。
                            </p>
                        </div>
                        @if ($yixiaoerPublishedKeyword !== '')
                            <span class="inline-flex items-center gap-2 rounded-lg bg-green-50 px-3 py-2 text-xs font-medium text-green-700">
                                <i data-lucide="search-check" class="h-4 w-4"></i>
                                关键词：{{ $yixiaoerPublishedKeyword }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-4 space-y-4">
                        @forelse ($yixiaoerPlatformGroups as $platformGroup)
                            @php
                                $platformStats = (array) ($platformGroup['stats'] ?? []);
                                $accountGroups = collect($platformGroup['accounts'] ?? []);
                            @endphp
                            <div class="overflow-hidden rounded-lg border border-gray-200">
                                <div class="flex flex-col gap-3 bg-gray-50 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gray-900 text-white">
                                            <i data-lucide="radio-tower" class="h-4 w-4"></i>
                                        </span>
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">{{ $platformGroup['platform'] }}</div>
                                            <div class="mt-1 text-xs text-gray-500">{{ $formatYixiaoerMetric($platformStats['works'] ?? 0) }} 条作品 · {{ $accountGroups->count() }} 个账号</div>
                                        </div>
                                    </div>
                                    <div class="grid gap-3 text-xs text-gray-600 sm:grid-cols-3">
                                        <div>播放/阅读 <span class="font-semibold text-gray-900">{{ $formatYixiaoerMetric($platformStats['play'] ?? 0) }} / {{ $formatYixiaoerMetric($platformStats['read'] ?? 0) }}</span></div>
                                        <div>点赞/评论 <span class="font-semibold text-gray-900">{{ $formatYixiaoerMetric($platformStats['likes'] ?? 0) }} / {{ $formatYixiaoerMetric($platformStats['comments'] ?? 0) }}</span></div>
                                        <div>收藏/分享 <span class="font-semibold text-gray-900">{{ $formatYixiaoerMetric($platformStats['collects'] ?? 0) }} / {{ $formatYixiaoerMetric($platformStats['shares'] ?? 0) }}</span></div>
                                    </div>
                                </div>

                                <div class="divide-y divide-gray-100 bg-white">
                                    @foreach ($accountGroups as $accountGroup)
                                        @php
                                            $accountStats = (array) ($accountGroup['stats'] ?? []);
                                            $accountItems = collect($accountGroup['items'] ?? []);
                                        @endphp
                                        <div>
                                            <div class="flex flex-col gap-2 px-4 py-3 md:flex-row md:items-center md:justify-between">
                                                <div>
                                                    <div class="font-medium text-gray-900">{{ $accountGroup['account_name'] }}</div>
                                                    <div class="mt-1 text-xs text-gray-500">{{ $formatYixiaoerMetric($accountStats['works'] ?? 0) }} 条作品 · 互动 {{ $formatYixiaoerMetric($accountStats['engagement'] ?? 0) }}</div>
                                                </div>
                                                <div class="grid gap-2 text-xs text-gray-600 sm:grid-cols-3">
                                                    <span>播放/阅读 {{ $formatYixiaoerMetric($accountStats['play'] ?? 0) }} / {{ $formatYixiaoerMetric($accountStats['read'] ?? 0) }}</span>
                                                    <span>点赞/评论 {{ $formatYixiaoerMetric($accountStats['likes'] ?? 0) }} / {{ $formatYixiaoerMetric($accountStats['comments'] ?? 0) }}</span>
                                                    <span>收藏/分享 {{ $formatYixiaoerMetric($accountStats['collects'] ?? 0) }} / {{ $formatYixiaoerMetric($accountStats['shares'] ?? 0) }}</span>
                                                </div>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full divide-y divide-gray-100 text-sm">
                                                    <thead class="bg-white text-left text-xs font-semibold text-gray-500">
                                                        <tr>
                                                            <th class="px-4 py-2">作品</th>
                                                            <th class="px-4 py-2">类型</th>
                                                            <th class="px-4 py-2">时间</th>
                                                            <th class="px-4 py-2 text-right">播放/阅读</th>
                                                            <th class="px-4 py-2 text-right">点赞</th>
                                                            <th class="px-4 py-2 text-right">评论</th>
                                                            <th class="px-4 py-2 text-right">收藏/分享</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100">
                                                        @foreach ($accountItems as $item)
                                                            <tr>
                                                                <td class="max-w-sm px-4 py-3">
                                                                    @if (($item['url'] ?? '') !== '')
                                                                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="line-clamp-2 font-medium text-blue-700 hover:text-blue-900">{{ $item['title'] }}</a>
                                                                    @else
                                                                        <div class="line-clamp-2 font-medium text-gray-900">{{ $item['title'] }}</div>
                                                                    @endif
                                                                </td>
                                                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ $item['type_label'] }}</td>
                                                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ $item['date'] ?: '—' }}</td>
                                                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-gray-900">{{ $item['play'] }} / {{ $item['read'] }}</td>
                                                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-700">{{ $item['like'] }}</td>
                                                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-700">{{ $item['comment'] }}</td>
                                                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-700">{{ $item['collect'] }} / {{ $item['share'] }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500">
                                没有匹配的作品数据，换个关键词再筛选。
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">GEO 全链路执行台</h2>
                    <p class="mt-1 text-sm text-gray-500">从品牌资料到真实搜索、引用源、文章、素材、公众号交接和复测，按顺序推进。</p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white">
                    <i data-lucide="route" class="h-4 w-4"></i>
                    {{ $geoWorkflowReadyCount }} / {{ $geoWorkflowTotal }} 已推进
                </div>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-3 xl:grid-cols-9">
                @foreach ($geoWorkflowSteps as $step)
                    <a href="{{ $step['href'] }}" data-geo-chain-step="{{ $step['key'] }}" class="group rounded-lg border {{ $step['done'] ? 'border-emerald-100 bg-emerald-50/40' : 'border-gray-200 bg-white' }} p-3 transition hover:border-blue-200 hover:bg-blue-50/50">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold {{ $step['done'] ? 'text-emerald-700' : 'text-gray-500' }}">{{ $step['number'] }}</span>
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg {{ $step['done'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                <i data-lucide="{{ $step['icon'] }}" class="h-4 w-4"></i>
                            </span>
                        </div>
                        <div class="mt-3 text-sm font-semibold text-gray-900">{{ $step['label'] }}</div>
                        <div class="mt-1 min-h-[2.25rem] text-xs leading-5 text-gray-500">{{ $step['description'] }}</div>
                        <div class="mt-3 inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium {{ $step['done'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ $step['metric'] }}
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-5 border-t border-gray-100 pt-5">
                <div class="mb-3 text-sm font-semibold text-gray-900">运营底座</div>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach ($geoOperationsLinks as $link)
                        <a href="{{ $link['href'] }}" class="flex items-center gap-3 rounded-lg border border-gray-200 px-4 py-3 transition hover:border-blue-200 hover:bg-blue-50/50">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600">
                                <i data-lucide="{{ $link['icon'] }}" class="h-4 w-4"></i>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-gray-900">{{ $link['label'] }}</span>
                                <span class="mt-1 block truncate text-xs text-gray-500">{{ $link['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
        </div>

        <div data-geo-tab-panel="external-qa" class="hidden space-y-6">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">外部问答检视</h2>
                        <p class="mt-1 text-sm text-gray-500">先选一个预设检视快速开始，也可以自己写问题矩阵，检查 AI 回答里的品牌命中、推荐、竞品、引用来源和原始证据。</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-lg bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700">
                        <i data-lucide="shield-check" class="h-4 w-4"></i>
                        证据优先
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">检视批次</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ (int) $externalInspection['total_runs'] }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">原始回答</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ (int) $externalInspection['total_answers'] }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">品牌命中率</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ (int) $externalInspection['brand_mention_rate'] }}%</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">目标预期值</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ $externalInspection['target_keyword_hit_rate'] !== null ? (int) $externalInspection['target_keyword_hit_rate'].'%' : '—' }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">关键词命中率</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ (int) $externalInspection['keyword_hit_rate'] }}%</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs font-medium text-gray-500">平均可见度分</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900">{{ $externalInspection['average_score'] ?? '—' }}</div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">预设检视</h2>
                        <p class="mt-1 text-sm text-gray-500">不知道怎么问时，先选一个场景，系统会自动填好检视名称和问题矩阵。</p>
                    </div>
                    <span class="inline-flex w-fit items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-xs font-medium text-slate-600">
                        <i data-lucide="mouse-pointer-click" class="h-3.5 w-3.5"></i>
                        点击后可继续编辑
                    </span>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @foreach($inspectionPresets as $preset)
                        <button
                            type="button"
                            data-geo-inspection-preset
                            data-preset-name="{{ $preset['name'] }}"
                            data-preset-questions="{{ $preset['questions_text'] }}"
                            data-preset-target="{{ (int) $preset['target'] }}"
                            class="group rounded-lg border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-blue-200 hover:bg-blue-50"
                        >
                            <span class="flex items-center justify-between gap-3">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-white text-slate-600 ring-1 ring-slate-200 group-hover:text-blue-700">
                                    <i data-lucide="{{ $preset['icon'] }}" class="h-4 w-4"></i>
                                </span>
                                <span class="rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-500 ring-1 ring-slate-200">使用预设</span>
                            </span>
                            <span class="mt-4 block text-sm font-semibold text-slate-950">{{ $preset['name'] }}</span>
                            <span class="mt-1 block min-h-10 text-xs leading-5 text-slate-500">{{ $preset['description'] }}</span>
                            <span class="mt-3 block text-xs leading-5 text-slate-500">{{ count($preset['questions']) }} 个问题 · 目标 {{ (int) $preset['target'] }}%</span>
                        </button>
                    @endforeach
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(360px,0.95fr)]">
                <section data-geo-custom-inspection class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">自定义检视</h2>
                        <p class="mt-1 text-sm text-gray-500">可直接用预设生成，也可以一行一个问题自定义，建议覆盖品牌词、品类推荐、避坑、对比和价格意图。</p>
                    </div>
                    <form id="geo-web-workbench-open-login-form" method="POST" action="{{ route('admin.geo.web-workbench.open') }}" class="hidden" data-geo-workbench-async>
                        @csrf
                        <input type="hidden" name="return_tab" value="external-qa">
                    </form>
                    <form id="geo-web-workbench-check-logins-form" method="POST" action="{{ route('admin.geo.web-workbench.check-logins') }}" class="hidden" data-geo-workbench-async>
                        @csrf
                        <input type="hidden" name="return_tab" value="external-qa">
                        @foreach ($webWorkbenchPlatformStatuses as $platformStatus)
                            @php
                                $checkPlatformId = (string) ($platformStatus['platformId'] ?? '');
                            @endphp
                            @if ($checkPlatformId !== '')
                                <input type="hidden" name="platform_ids[]" value="{{ $checkPlatformId }}">
                            @endif
                        @endforeach
                    </form>
                    <form method="POST" action="{{ route('admin.geo.external-inspections.store') }}" class="space-y-5 p-5">
                        @csrf
                        <input type="hidden" name="return_tab" value="external-qa">
                        <div>
                            <label for="external_inspection_name" class="block text-sm font-medium text-gray-700">检视名称</label>
                            <input id="external_inspection_name" name="name" type="text" value="{{ old('name') }}" placeholder="例如：首轮外部问答检视" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label for="external_target_keyword_hit_rate" class="block text-sm font-medium text-gray-700">预期关键词命中率</label>
                            <div class="mt-2 flex items-center gap-3">
                                <input id="external_target_keyword_hit_rate" name="target_keyword_hit_rate" type="number" min="0" max="100" step="1" value="{{ old('target_keyword_hit_rate', $externalInspection['target_keyword_hit_rate'] ?? 70) }}" class="block w-32 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                                <span class="text-sm text-gray-500">目标预期值，用来判断复测是否达标。</span>
                            </div>
                        </div>

                        <div>
                            <label for="external_questions_text" class="block text-sm font-medium text-gray-700">问题矩阵</label>
                            <textarea id="external_questions_text" name="questions_text" rows="8" required class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-6 focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('questions_text', $externalQuestionDefaults) }}</textarea>
                        </div>

                        <div data-geo-workbench-platform-module data-status-url="{{ route('admin.geo.web-workbench.platform-statuses') }}">
                            @include('admin.geo.partials.web-workbench-platform-options')
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs leading-5 text-gray-500">创建后先保存批次和问题，点击“运行检视”后才会产生原始回答与引用证据。</p>
                            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="radar" class="h-4 w-4"></i>
                                创建检视任务
                            </button>
                        </div>
                    </form>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">最新检视</h2>
                            <p class="mt-1 text-xs text-gray-500">{{ (int) $externalInspection['platform_count'] }} 个平台参与过检视 · 引用率 {{ (int) $externalInspection['citation_rate'] }}%</p>
                        </div>
                        @if ($externalInspectionLatest)
                            <a href="{{ route('admin.geo.search-runs.show', ['runId' => (int) $externalInspectionLatest->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                <i data-lucide="file-search" class="h-3.5 w-3.5"></i>
                                查看证据
                            </a>
                        @endif
                    </div>
                    <div class="grid gap-0 border-b border-gray-100 lg:grid-cols-2">
                        <div class="border-b border-gray-100 p-5 lg:border-b-0 lg:border-r">
                            <h3 class="text-sm font-semibold text-gray-900">创作文章优化方向</h3>
                            <p class="mt-1 text-xs text-gray-500">根据检视名称和问题矩阵，生成下一篇文章要补的内容。</p>
                            <div class="mt-4 space-y-3">
                                @forelse ($latestOptimizationDirections as $direction)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2">
                                        <div class="text-xs font-semibold text-gray-900">{{ $direction['title'] ?? '优化方向' }}</div>
                                        <div class="mt-1 text-xs leading-5 text-gray-600">{{ $direction['body'] ?? '' }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-lg border border-dashed border-gray-200 px-3 py-5 text-center text-xs text-gray-500">
                                        创建检视后，系统会在这里给出文章选题、结构和关键词补齐方向。
                                    </div>
                                @endforelse
                            </div>
                        </div>
                        <div class="p-5">
                            <h3 class="text-sm font-semibold text-gray-900">多轮优化波动图</h3>
                            <p class="mt-1 text-xs text-gray-500">记录每轮复测的关键词命中率，观察优化后是否稳定上升。</p>
                            <div class="mt-4" data-geo-keyword-hit-trend>
                                @php
                                    $trendCount = $externalKeywordHitTrend->count();
                                    $trendPoints = [];
                                    $trendCircles = [];
                                    foreach ($externalKeywordHitTrend->values() as $index => $point) {
                                        $x = $trendCount <= 1 ? 50 : round($index / max(1, $trendCount - 1) * 100, 2);
                                        $y = round(100 - max(0, min(100, (int) $point['rate'])), 2);
                                        $trendPoints[] = $x.','.$y;
                                        $trendCircles[] = ['x' => $x, 'y' => $y, 'rate' => (int) $point['rate'], 'name' => (string) $point['name']];
                                    }
                                @endphp
                                @if ($trendCount > 0)
                                    <div class="h-32 rounded-lg border border-gray-100 bg-gray-50 p-3">
                                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-full w-full overflow-visible">
                                            <line x1="0" y1="100" x2="100" y2="100" stroke="#e5e7eb" stroke-width="1"></line>
                                            <line x1="0" y1="50" x2="100" y2="50" stroke="#e5e7eb" stroke-width="0.7" stroke-dasharray="3 3"></line>
                                            <polyline points="{{ implode(' ', $trendPoints) }}" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
                                            @foreach ($trendCircles as $circle)
                                                <circle cx="{{ $circle['x'] }}" cy="{{ $circle['y'] }}" r="2.8" fill="#2563eb"></circle>
                                            @endforeach
                                        </svg>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($externalKeywordHitTrend as $point)
                                            <span class="rounded-lg bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">{{ $point['name'] }} {{ (int) $point['rate'] }}%</span>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="rounded-lg border border-dashed border-gray-200 px-3 py-8 text-center text-xs text-gray-500">
                                        完成至少一轮检视后显示命中率波动。
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="mx-5 mt-4 hidden rounded-lg border px-3 py-2 text-xs font-medium" data-geo-search-run-delete-message></div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($externalInspectionRuns as $run)
                            @php
                                $runAnswers = collect($run->answers ?? []);
                                $runAnswerCount = $runAnswers->count();
                                $runTotalQuestions = max(0, (int) $run->total_questions);
                                $runProcessedQuestions = min($runTotalQuestions, (int) $run->completed_questions + (int) $run->failed_questions);
                                $runProgressPercent = $runTotalQuestions > 0 ? (int) round($runProcessedQuestions / $runTotalQuestions * 100) : 0;
                                $runBrandMentionRate = $runAnswerCount > 0
                                    ? (int) round($runAnswers->where('brand_mentioned', true)->count() / $runAnswerCount * 100)
                                    : 0;
                                $runCitationRate = $runAnswerCount > 0
                                    ? (int) round($runAnswers->filter(fn ($answer): bool => ((array) $answer->citations) !== [] || ((array) $answer->source_urls) !== [])->count() / $runAnswerCount * 100)
                                    : 0;
                                $runAverageScore = $runAnswerCount > 0 ? (int) round((float) $runAnswers->avg('visibility_score')) : null;
                                $runKeywordHitRate = $run->keyword_hit_rate !== null ? (int) $run->keyword_hit_rate : $runBrandMentionRate;
                                $runTargetKeywordHitRate = $run->target_keyword_hit_rate !== null ? (int) $run->target_keyword_hit_rate : null;
                                $runPreviousKeywordHitRate = $run->previous_keyword_hit_rate !== null ? (int) $run->previous_keyword_hit_rate : null;
                                $runKeywordHitRateDelta = $run->keyword_hit_rate_delta !== null ? (int) $run->keyword_hit_rate_delta : null;
                                $runKeywordDeltaLabel = $runKeywordHitRateDelta === null ? '—' : (($runKeywordHitRateDelta > 0 ? '+' : '').$runKeywordHitRateDelta.'%');
                                $runStatusClass = match ($run->status) {
                                    'completed' => 'bg-emerald-50 text-emerald-700',
                                    'running' => 'bg-blue-50 text-blue-700',
                                    'partial_failed' => 'bg-amber-50 text-amber-700',
                                    'failed' => 'bg-red-50 text-red-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                                $runProgressClass = match ($run->status) {
                                    'completed' => 'bg-emerald-500',
                                    'running' => 'bg-blue-500',
                                    'partial_failed' => 'bg-amber-500',
                                    'failed' => 'bg-red-500',
                                    default => 'bg-gray-400',
                                };
                                $runTimedOut = str_contains((string) $run->error_message, '运行超时');
                                $runStatusLabel = $runTimedOut ? '运行超时，可重试' : ($searchRunStatusLabels[$run->status] ?? $run->status);
                                $runButtonLabel = in_array($run->status, ['failed', 'partial_failed'], true) ? '重新运行检视' : '运行检视';
                            @endphp
                            <div class="p-5" data-geo-search-run-card="{{ (int) $run->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $run->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">批次 #{{ (int) $run->id }} · 问题 {{ (int) $run->total_questions }} · 回答 {{ (int) $run->answers_count }} · 平均可见度 {{ $runAverageScore ?? '—' }}</div>
                                    </div>
                                    <span class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium {{ $runStatusClass }}">{{ $runStatusLabel }}</span>
                                </div>

                                <div class="mt-3">
                                    <div class="flex items-center justify-between gap-3 text-xs text-gray-500">
                                        <span>进度 {{ $runProcessedQuestions }} / {{ $runTotalQuestions }} · {{ $runProgressPercent }}%</span>
                                        <span>成功 {{ (int) $run->completed_questions }} · 失败 {{ (int) $run->failed_questions }}</span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                                        <div class="h-2 rounded-full {{ $runProgressClass }}" style="width: {{ $runProgressPercent }}%"></div>
                                    </div>
                                    <div class="mt-2 text-xs font-medium text-blue-700">关键词命中 {{ $runKeywordHitRate }}% / 目标 {{ $runTargetKeywordHitRate !== null ? $runTargetKeywordHitRate.'%' : '—' }} · 上轮 {{ $runPreviousKeywordHitRate !== null ? $runPreviousKeywordHitRate.'%' : '—' }} · 变化 {{ $runKeywordDeltaLabel }}</div>
                                    <div class="mt-2 text-xs font-medium text-gray-700">结果：品牌命中 {{ $runBrandMentionRate }}% · 引用率 {{ $runCitationRate }}% · 平均 {{ $runAverageScore ?? '—' }}</div>
                                    @if ($run->error_message)
                                        <div class="mt-2 line-clamp-2 text-xs text-red-600">{{ $run->error_message }}</div>
                                    @endif
                                </div>

                                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                    <div class="text-xs text-gray-500">{{ $run->finished_at?->format('Y-m-d H:i') ?: '尚未完成' }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        @if (in_array($run->status, ['pending', 'failed', 'partial_failed'], true))
                                            <form method="POST" action="{{ route('admin.geo.search-runs.run', ['runId' => (int) $run->id]) }}" data-geo-run-form>
                                                @csrf
                                                <button type="submit" data-geo-run-submit data-running-label="运行中..." class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:cursor-wait disabled:bg-blue-400">
                                                    <i data-lucide="play" class="h-3.5 w-3.5"></i>
                                                    <span data-geo-run-label>{{ $runButtonLabel }}</span>
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('admin.geo.search-runs.show', ['runId' => (int) $run->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                            <i data-lucide="file-search" class="h-3.5 w-3.5"></i>
                                            查看结果
                                        </a>
                                        @if ($run->status !== 'running')
                                            <form method="POST" action="{{ route('admin.geo.search-runs.delete', ['runId' => (int) $run->id]) }}" onsubmit="return confirm(@js('确定删除这个检视批次吗？对应的问题和回答会一起删除。'));" data-geo-search-run-delete-form>
                                                @csrf
                                                <button type="submit" data-geo-search-run-delete-submit data-deleting-label="删除中..." class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 disabled:cursor-wait disabled:bg-red-100 disabled:text-red-400">
                                                    <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                                                    <span data-geo-search-run-delete-label>删除</span>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">暂无外部问答检视批次</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        <div data-geo-tab-panel="search" class="hidden space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">机会到引用链路</h2>
                    <p class="mt-1 text-sm text-gray-500">先补品牌和关键词，再调用真实 AI 搜索，最后进入引用源分析和文章生产。</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="#setup" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="database" class="h-4 w-4"></i>
                        品牌与关键词
                    </a>
                    <a href="#ai-platforms" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="bot" class="h-4 w-4"></i>
                        真实AI平台
                    </a>
                    <a href="{{ route('admin.geo.citation-sources.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="link" class="h-4 w-4"></i>
                        引用源分析
                    </a>
                    <a href="#articles" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="newspaper" class="h-4 w-4"></i>
                        去文章管理
                    </a>
                </div>
            </div>
        </section>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,0.8fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">关键词机会库</h2>
                        <p class="mt-1 text-sm text-gray-500">从企业资料批量生成可用于 GEO 搜索的机会词</p>
                    </div>
                    <form method="POST" action="{{ route('admin.geo.opportunities.generate') }}" class="flex items-center gap-2">
                        @csrf
                        <input type="hidden" name="limit" value="12">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="sparkles" class="h-4 w-4"></i>
                            生成机会词
                        </button>
                    </form>
                </div>

                <div class="border-b border-gray-100 p-5">
                    <form method="POST" action="{{ route('admin.geo.opportunities.expand') }}" class="space-y-4">
                        @csrf
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">手工拓词</h3>
                                <p class="mt-1 text-xs text-gray-500">A 地域、B 修饰、C 核心、D 实体、E 推荐、F 问法</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="manual_expansion_limit" class="text-xs font-medium text-gray-600">数量</label>
                                <input id="manual_expansion_limit" name="limit" type="number" min="1" max="200" value="{{ old('limit', $manualExpansionDefaults['limit']) }}" class="h-9 w-20 rounded-lg border border-gray-300 px-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-3">
                            <div>
                                <label for="manual_area_prefixes" class="block text-xs font-medium text-gray-700">A 地域前缀</label>
                                <textarea id="manual_area_prefixes" name="area_prefixes" rows="3" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('area_prefixes', $manualExpansionDefaults['area_prefixes']) }}</textarea>
                            </div>
                            <div>
                                <label for="manual_modifiers" class="block text-xs font-medium text-gray-700">B 修饰词</label>
                                <textarea id="manual_modifiers" name="modifiers" rows="3" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('modifiers', $manualExpansionDefaults['modifiers']) }}</textarea>
                            </div>
                            <div>
                                <label for="manual_core_terms" class="block text-xs font-medium text-gray-700">C 核心产品词</label>
                                <textarea id="manual_core_terms" name="core_terms" rows="3" required class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('core_terms', $manualExpansionDefaults['core_terms']) }}</textarea>
                            </div>
                            <div>
                                <label for="manual_entity_terms" class="block text-xs font-medium text-gray-700">D 实体类型词</label>
                                <textarea id="manual_entity_terms" name="entity_terms" rows="3" required class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('entity_terms', $manualExpansionDefaults['entity_terms']) }}</textarea>
                            </div>
                            <div>
                                <label for="manual_recommend_terms" class="block text-xs font-medium text-gray-700">E 推荐词</label>
                                <textarea id="manual_recommend_terms" name="recommend_terms" rows="3" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('recommend_terms', $manualExpansionDefaults['recommend_terms']) }}</textarea>
                            </div>
                            <div>
                                <label for="manual_question_terms" class="block text-xs font-medium text-gray-700">F 问法词</label>
                                <textarea id="manual_question_terms" name="question_terms" rows="3" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('question_terms', $manualExpansionDefaults['question_terms']) }}</textarea>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                            <div class="grid flex-1 gap-2 sm:grid-cols-3">
                                @foreach ($manualExpansionPatterns as $patternValue => $patternLabel)
                                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                        <input type="checkbox" name="combination_patterns[]" value="{{ $patternValue }}" @checked(in_array($patternValue, $selectedManualExpansionPatterns, true)) class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span>{{ $patternLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                <i data-lucide="plus" class="h-4 w-4"></i>
                                生成拓展机会词
                            </button>
                        </div>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.geo.search-runs.store') }}" class="space-y-4 p-5">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                        <div>
                            <label for="search_run_name" class="block text-sm font-medium text-gray-700">批次名称</label>
                            <input id="search_run_name" name="name" type="text" value="{{ old('name') }}" placeholder="例如：第一批 GEO 机会搜索" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">可选机会</label>
                            <div class="mt-2 text-sm text-gray-500">{{ $opportunities->count() }} 个</div>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">选择</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">机会词</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">意图</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">机会分</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">理由</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($opportunities as $opportunity)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <input type="checkbox" name="opportunity_ids[]" value="{{ (int) $opportunity->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        </td>
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                            <span>{{ $opportunity->keyword }}</span>
                                            @if ($opportunity->cluster_name)
                                                <span class="mt-1 block text-xs font-normal text-gray-500">{{ $opportunity->cluster_name }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $opportunity->intent }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-emerald-700">{{ (int) $opportunity->opportunity_score }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $opportunity->rationale }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">还没有关键词机会，先生成一批</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-medium text-gray-700">真实搜索平台</div>
                        <div class="grid gap-2 md:grid-cols-3">
                            @foreach ($realSearchPlatforms as $platform)
                                <label class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50/60 px-3 py-2 text-sm text-gray-700">
                                    <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" checked class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate">{{ $platform->name }}</span>
                                        <span class="block truncate text-xs text-blue-700">真实网页 AI 搜索</span>
                                    </span>
                                </label>
                            @endforeach
                            @foreach ($realAiModels as $model)
                                <label class="flex items-center gap-2 rounded-lg border border-blue-100 bg-blue-50/40 px-3 py-2 text-sm text-gray-700">
                                    <input type="checkbox" name="platform_codes[]" value="ai_model:{{ (int) $model->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="min-w-0">
                                        <span class="block truncate">{{ $model->name }}</span>
                                        <span class="block truncate text-xs text-gray-500">{{ $model->model_id }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($mockSearchPlatforms->isNotEmpty())
                            <details class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                <summary class="cursor-pointer text-xs font-medium text-gray-600">测试备用平台</summary>
                                <div class="mt-3 grid gap-2 md:grid-cols-3">
                                    @foreach ($mockSearchPlatforms as $platform)
                                        <label class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
                                            <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span>{{ $platform->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" @disabled($opportunities->isEmpty()) class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            创建 AI 搜索批次
                        </button>
                    </div>
                </form>
            </section>

            <div class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">AI 搜索批次</h2>
                        <span class="text-sm text-gray-500">{{ $searchRuns->count() }} 条</span>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($searchRuns as $run)
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $run->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">问题 {{ (int) $run->total_questions }} · 平均可见度 {{ (int) $run->average_score }}</div>
                                    </div>
                                    <span class="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600">{{ $searchRunStatusLabels[$run->status] ?? $run->status }}</span>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="text-xs text-gray-500">完成 {{ (int) $run->completed_questions }} / 失败 {{ (int) $run->failed_questions }}</div>
                                    @if (in_array($run->status, ['pending', 'failed', 'partial_failed'], true))
                                        <form method="POST" action="{{ route('admin.geo.search-runs.run', ['runId' => (int) $run->id]) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                <i data-lucide="play" class="h-3.5 w-3.5"></i>
                                                运行搜索
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">暂无 AI 搜索批次</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">引用来源库</h2>
                            <p class="mt-1 text-xs text-gray-500">采集页面并筛选可借鉴参考内容</p>
                        </div>
                        <a href="{{ route('admin.geo.citation-sources.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">查看全部</a>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($citationSources as $source)
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $source->domain ?: '未知域名' }}</div>
                                        <a href="{{ $source->url }}" target="_blank" class="mt-1 block truncate text-xs text-blue-600 hover:text-blue-700">{{ $source->url }}</a>
                                    </div>
                                    <span class="shrink-0 rounded-lg bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">{{ $citationSourceStatusLabels[$source->status] ?? $source->status }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                    <span>引用 {{ (int) $source->citation_count }} 次</span>
                                    <span>·</span>
                                    <span>{{ $source->last_seen_at?->format('Y-m-d H:i') }}</span>
                                    @if ($source->latestPageSnapshot?->latestScore)
                                        <span class="rounded-lg bg-emerald-50 px-2 py-1 font-medium text-emerald-700">评分 {{ (int) $source->latestPageSnapshot->latestScore->total_score }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 flex justify-end">
                                    <a href="{{ route('admin.geo.citation-sources.show', ['sourceId' => (int) $source->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                        <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                                        查看
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">运行 AI 搜索后会自动沉淀引用来源</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
        </div>

        <div data-geo-tab-panel="ai-platforms" class="hidden space-y-6">
            <section data-geo-web-workbench-panel class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">搜索 AI 平台</h2>
                        <p class="mt-1 text-sm text-gray-500">本机真实网页 AI 搜索软件、可用模型和测试备用平台集中管理。</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.geo.web-workbench.open') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="external-link" class="h-4 w-4"></i>
                                打开搜索软件
                            </button>
                        </form>
                        <a href="#search" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            去机会搜索
                        </a>
                        <a href="{{ route('admin.ai.configurator') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="sliders-horizontal" class="h-4 w-4"></i>
                            AI配置器
                        </a>
                        <a href="{{ route('admin.site-settings.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="settings" class="h-4 w-4"></i>
                            站点设置
                        </a>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.75fr)]">
                    <div class="rounded-lg border border-blue-100 bg-blue-50/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-900">本机多平台AI搜索工作台</div>
                                <div class="mt-1 text-xs text-gray-500">平台编码 ai_web_workbench，会用于 GEO 诊断和机会搜索。</div>
                            </div>
                            <span class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-medium {{ $webWorkbenchReady ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                {{ $webWorkbenchReady ? '已接入' : '未启用' }}
                            </span>
                        </div>
                        <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                            <div>
                                <div class="text-xs text-gray-500">调用命令</div>
                                <div class="mt-1 truncate font-mono text-xs font-medium text-gray-900">{{ $webWorkbenchCommand }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">状态同步</div>
                                <div class="mt-1 font-medium {{ ((bool) ($webWorkbenchStatus['ok'] ?? false)) ? 'text-emerald-700' : 'text-amber-700' }}">
                                    {{ ((bool) ($webWorkbenchStatus['ok'] ?? false)) ? '可读取' : '待同步' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">最近任务</div>
                                <div class="mt-1 font-medium text-gray-900">{{ $webWorkbenchTasks->count() }} 条</div>
                            </div>
                        </div>
                        @if (! ((bool) ($webWorkbenchStatus['ok'] ?? false)) && trim((string) ($webWorkbenchStatus['error'] ?? '')) !== '')
                            <div class="mt-4 rounded-lg border border-amber-200 bg-white/80 px-3 py-2 text-xs text-amber-800">
                                {{ $webWorkbenchStatus['error'] }}
                            </div>
                        @endif
                    </div>

                    <div class="rounded-lg border border-gray-100 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-gray-900">诊断默认平台</div>
                            <span class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">真实搜索优先</span>
                        </div>
                        <div class="mt-4 space-y-2 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">真实搜索平台</span>
                                <span class="font-medium text-gray-900">{{ $realSearchPlatforms->count() }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">真实 AI 模型</span>
                                <span class="font-medium text-gray-900">{{ $realAiModels->count() }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">测试备用平台</span>
                                <span class="font-medium text-gray-900">{{ $mockSearchPlatforms->count() }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-gray-100 bg-white p-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">平台监视</h3>
                            <p class="mt-1 text-xs text-gray-500">执行检测时会读取各网页 AI 的登录状态；未正常登录的平台会提示先打开搜索软件登录。</p>
                        </div>
                        <span class="w-fit rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{{ $webWorkbenchPlatformStatuses->count() }} 个网页平台</span>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @forelse ($webWorkbenchPlatformStatuses as $platformStatus)
                            @php
                                $platformId = (string) ($platformStatus['platformId'] ?? '');
                                $platformName = (string) ($platformStatus['platformName'] ?? $platformId);
                                $loginOk = $platformStatus['loginOk'] ?? null;
                                $statusLabel = (string) ($platformStatus['loginStatus'] ?? '未检测');
                                $statusClass = $loginOk === true
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : ($loginOk === false ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600');
                            @endphp
                            <article class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $platformName }}</div>
                                        <div class="mt-1 font-mono text-xs text-gray-500">{{ $platformId }}</div>
                                    </div>
                                    <span class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-500">
                                    <span>完成 {{ (int) ($platformStatus['completedCount'] ?? 0) }}</span>
                                    <span>总计 {{ (int) ($platformStatus['runCount'] ?? 0) }}</span>
                                </div>
                                @if (trim((string) ($platformStatus['loginHint'] ?? '')) !== '')
                                    <div class="mt-2 text-xs leading-5 text-gray-500">{{ $platformStatus['loginHint'] }}</div>
                                @endif
                                @if (trim((string) ($platformStatus['lastError'] ?? '')) !== '')
                                    <div class="mt-2 line-clamp-2 text-xs text-amber-700">{{ $platformStatus['lastError'] }}</div>
                                @endif
                                <form method="POST" action="{{ route('admin.web-workbench.run') }}" class="mt-3">
                                    @csrf
                                    <input type="hidden" name="question" value="账号登录状态检测：请用一句话回复当前平台可以正常回答。">
                                    <input type="hidden" name="platform_ids[]" value="{{ $platformId }}">
                                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @disabled($platformId === '')>
                                        <i data-lucide="radar" class="h-4 w-4"></i>
                                        单平台检测
                                    </button>
                                </form>
                            </article>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-4">
                                暂未同步到内部平台。打开搜索软件并执行一次检测后，这里会显示每个平台的登录状态。
                            </div>
                        @endforelse
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(360px,0.85fr)]">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">已接入真实平台</h2>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($realSearchPlatforms as $platform)
                            <div class="flex items-center justify-between gap-3 p-5">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900">{{ $platform->name }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $platform->code }} · 每次 {{ (int) $platform->cost_per_query }} 点</div>
                                </div>
                                <span class="shrink-0 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">真实网页搜索</span>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">暂无真实搜索平台</div>
                        @endforelse

                        @foreach ($realAiModels as $model)
                            <div class="flex items-center justify-between gap-3 p-5">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900">{{ $model->name }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $model->model_id }}</div>
                                </div>
                                <span class="shrink-0 rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">真实聊天模型</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($mockSearchPlatforms->isNotEmpty())
                        <details class="border-t border-gray-100 px-5 py-4">
                            <summary class="cursor-pointer text-sm font-medium text-gray-700">测试备用平台</summary>
                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                @foreach ($mockSearchPlatforms as $platform)
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                                        <div class="font-medium text-gray-800">{{ $platform->name }}</div>
                                        <div class="mt-1 font-mono text-xs">{{ $platform->code }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">最近真实搜索</h2>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($webWorkbenchTasks->take(5) as $task)
                            <div class="p-5">
                                <div class="truncate text-sm font-semibold text-gray-900">{{ $task['title'] ?? $task['question'] ?? $task['id'] ?? '搜索任务' }}</div>
                                <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                    <span>{{ $task['status'] ?? '未知状态' }}</span>
                                    <span>完成 {{ (int) ($task['completedCount'] ?? 0) }} / {{ (int) ($task['runCount'] ?? 0) }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-sm text-gray-500">打开搜索软件执行后，这里会同步最近任务。</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        <div data-geo-tab-panel="setup" class="hidden space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">诊断准备台</h2>
                    <p class="mt-1 text-sm text-gray-500">品牌资料、关键词和 AI 平台三项齐备后即可创建诊断。</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700">
                        <i data-lucide="database" class="h-3.5 w-3.5"></i>
                        品牌 {{ $brandProfile ? '已保存' : '未保存' }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700">
                        <i data-lucide="list-checks" class="h-3.5 w-3.5"></i>
                        关键词 {{ $keywords->count() }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-lg bg-violet-50 px-3 py-1.5 text-xs font-medium text-violet-700">
                        <i data-lucide="sparkles" class="h-3.5 w-3.5"></i>
                        真实平台 {{ $activePlatformCount }}
                    </span>
                </div>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.8fr)]">
                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium text-gray-900">品牌完整度</div>
                            <div class="mt-1 text-xs text-gray-500">已填 {{ $brandReadinessFilled }} / {{ $brandReadinessTotal }}</div>
                        </div>
                        <div class="text-2xl font-semibold text-gray-900">{{ $brandReadinessPercent }}%</div>
                    </div>
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-blue-600" style="width: {{ $brandReadinessPercent }}%"></div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @forelse ($missingBrandReadinessItems->take(6) as $item)
                            <span class="rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">缺 {{ $item['label'] }}</span>
                        @empty
                            <span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">资料已完整</span>
                        @endforelse
                        @if ($missingBrandReadinessItems->count() > 6)
                            <span class="rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">还有 {{ $missingBrandReadinessItems->count() - 6 }} 项</span>
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-medium text-gray-900">诊断前检查</div>
                        <span class="rounded-lg px-2.5 py-1 text-xs font-medium {{ $diagnosisReady ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $diagnosisReady ? '可创建' : '待补齐' }}
                        </span>
                    </div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">品牌资料</span>
                            <span class="font-medium {{ $brandProfile ? 'text-emerald-700' : 'text-amber-700' }}">{{ $brandProfile ? '已保存' : '未保存' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">可诊断关键词</span>
                            <span class="font-medium text-gray-900">{{ $keywords->count() }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">真实 AI 平台</span>
                            <span class="font-medium text-gray-900">{{ $activePlatformCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">全选预估点数</span>
                            <span class="font-medium text-gray-900">{{ $fullDiagnosisCost }}</span>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(340px,0.85fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">品牌知识库</h2>
                            <p class="mt-1 text-sm text-gray-500">当前会写入诊断提示词和后续参考内容生成。</p>
                        </div>
                        <span class="rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">{{ $brandReadinessPercent }}%</span>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.geo.brand-profile.save') }}" class="space-y-5 p-5">
                    @csrf
                    <input type="hidden" name="return_tab" value="setup">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">基础识别</h3>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="organization_name" class="block text-sm font-medium text-gray-700">企业名称</label>
                            <input id="organization_name" name="organization_name" type="text" value="{{ old('organization_name', $organization->name) }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="brand_name" class="block text-sm font-medium text-gray-700">品牌名称</label>
                            <input id="brand_name" name="brand_name" type="text" value="{{ old('brand_name', $brandProfile?->brand_name ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label for="aliases_text" class="block text-sm font-medium text-gray-700">品牌别名</label>
                        <textarea id="aliases_text" name="aliases_text" rows="2" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('aliases_text', $aliasesText) }}</textarea>
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <h3 class="text-sm font-semibold text-gray-900">内容素材</h3>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="products" class="block text-sm font-medium text-gray-700">产品/服务</label>
                            <textarea id="products" name="products" rows="4" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('products', $brandProfile?->products ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="advantages" class="block text-sm font-medium text-gray-700">核心优势</label>
                            <textarea id="advantages" name="advantages" rows="4" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('advantages', $brandProfile?->advantages ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="cases" class="block text-sm font-medium text-gray-700">案例素材</label>
                            <textarea id="cases" name="cases" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('cases', $brandProfile?->cases ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="pain_points" class="block text-sm font-medium text-gray-700">客户痛点</label>
                            <textarea id="pain_points" name="pain_points" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('pain_points', $brandProfile?->pain_points ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="service_area" class="block text-sm font-medium text-gray-700">服务区域</label>
                            <input id="service_area" name="service_area" type="text" value="{{ old('service_area', $brandProfile?->service_area ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="extra_facts" class="block text-sm font-medium text-gray-700">补充事实</label>
                            <input id="extra_facts" name="extra_facts" type="text" value="{{ old('extra_facts', $brandProfile?->extra_facts ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold text-gray-900">GEO 写作规则</h3>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="short_name" class="block text-sm font-medium text-gray-700">品牌简称</label>
                                <input id="short_name" name="short_name" type="text" value="{{ old('short_name', $extendedProfile['short_name'] ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="writing_directions" class="block text-sm font-medium text-gray-700">写作方向</label>
                                <input id="writing_directions" name="writing_directions" type="text" value="{{ old('writing_directions', $extendedProfile['writing_directions'] ?? '') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="copy_types" class="block text-sm font-medium text-gray-700">文案类型</label>
                                <textarea id="copy_types" name="copy_types" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('copy_types', implode("\n", (array) ($extendedProfile['copy_types'] ?? []))) }}</textarea>
                            </div>
                            <div>
                                <label for="product_features" class="block text-sm font-medium text-gray-700">产品特点</label>
                                <textarea id="product_features" name="product_features" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('product_features', implode("\n", (array) ($extendedProfile['product_features'] ?? []))) }}</textarea>
                            </div>
                            <div>
                                <label for="brand_story" class="block text-sm font-medium text-gray-700">品牌故事</label>
                                <textarea id="brand_story" name="brand_story" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('brand_story', $extendedProfile['brand_story'] ?? '') }}</textarea>
                            </div>
                            <div>
                                <label for="trust_proofs" class="block text-sm font-medium text-gray-700">信任背书</label>
                                <textarea id="trust_proofs" name="trust_proofs" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('trust_proofs', implode("\n", (array) ($extendedProfile['trust_proofs'] ?? []))) }}</textarea>
                            </div>
                            <div>
                                <label for="promotion_regions" class="block text-sm font-medium text-gray-700">推广区域</label>
                                <textarea id="promotion_regions" name="promotion_regions" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('promotion_regions', implode("\n", (array) ($extendedProfile['promotion_regions'] ?? []))) }}</textarea>
                            </div>
                            <div>
                                <label for="forbidden_claims" class="block text-sm font-medium text-gray-700">禁用表达</label>
                                <textarea id="forbidden_claims" name="forbidden_claims" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('forbidden_claims', implode("\n", (array) ($extendedProfile['forbidden_claims'] ?? []))) }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="h-4 w-4"></i>
                            保存品牌资料
                        </button>
                    </div>
                </form>
            </section>

            <div class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-base font-semibold text-gray-900">关键词库</h2>
                            <span class="text-sm text-gray-500">{{ $keywords->count() }} 条</span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($keywordStats as $stat)
                                <span class="rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">{{ $stat['label'] }} {{ $stat['count'] }}</span>
                            @endforeach
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.geo.keywords.store') }}" class="space-y-4 p-5">
                        @csrf
                        <input type="hidden" name="return_tab" value="setup">
                        <div>
                            <label for="keyword" class="block text-sm font-medium text-gray-700">单个关键词/问题</label>
                            <input id="keyword" name="keyword" type="text" value="{{ old('keyword') }}" placeholder="涪陵全屋定制哪家好" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="keywords_text" class="block text-sm font-medium text-gray-700">批量添加关键词</label>
                            <textarea id="keywords_text" name="keywords_text" rows="4" placeholder="一行一个，也支持逗号或顿号分隔" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('keywords_text') }}</textarea>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="type" class="block text-sm font-medium text-gray-700">类型</label>
                                <select id="type" name="type" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                                    @foreach ($keywordTypeLabels as $type => $label)
                                        <option value="{{ $type }}" @selected(old('type', 'question') === $type)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="intent" class="block text-sm font-medium text-gray-700">意图</label>
                                <input id="intent" name="intent" type="text" value="{{ old('intent', 'commercial') }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                            <i data-lucide="plus" class="h-4 w-4"></i>
                            添加关键词
                        </button>
                    </form>

                    <div class="border-t border-gray-100 px-5 py-4">
                        @if ($keywords->isEmpty())
                            <p class="text-sm text-gray-500">暂无关键词</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($keywords as $keyword)
                                    <div class="flex items-start justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium text-gray-900">{{ $keyword->keyword }}</div>
                                            <div class="mt-1 text-xs text-gray-500">{{ $keywordTypeLabels[$keyword->type] ?? $keyword->type }} · {{ $keyword->intent ?: '未标注意图' }}</div>
                                        </div>
                                        <span class="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600">{{ $keyword->status }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">创建诊断任务</h2>
                                <p class="mt-1 text-sm text-gray-500">选中的关键词会逐条生成诊断问题。</p>
                            </div>
                            <span class="rounded-lg px-2.5 py-1 text-xs font-medium {{ $diagnosisReady ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $diagnosisReady ? '已就绪' : '未就绪' }}</span>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.geo.diagnosis.store') }}" class="space-y-5 p-5">
                        @csrf
                        <input type="hidden" name="return_tab" value="tasks">
                        @unless ($diagnosisReady)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                需要先补齐品牌资料、关键词和真实 AI 平台。
                            </div>
                        @endunless
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-gray-700">选择关键词</span>
                                <span class="flex items-center gap-2">
                                    <button type="button" data-geo-select-keywords="all" class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50">
                                        <i data-lucide="check-check" class="h-3.5 w-3.5"></i>
                                        全选
                                    </button>
                                    <button type="button" data-geo-select-keywords="none" class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50">
                                        <i data-lucide="x" class="h-3.5 w-3.5"></i>
                                        清空
                                    </button>
                                </span>
                            </div>
                            <div class="max-h-44 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                                @forelse ($keywords as $keyword)
                                    <label class="flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                        <input type="checkbox" name="keyword_ids[]" value="{{ $keyword->id }}" data-geo-diagnosis-keyword class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-gray-900">{{ $keyword->keyword }}</span>
                                            <span class="block text-xs text-gray-500">{{ $keywordTypeLabels[$keyword->type] ?? $keyword->type }} · {{ $keyword->intent ?: '未标注意图' }}</span>
                                        </span>
                                    </label>
                                @empty
                                    <div class="text-sm text-gray-500">暂无可选关键词</div>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <div class="mb-2 text-sm font-medium text-gray-700">真实搜索平台</div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($realSearchPlatforms as $platform)
                                    <label class="flex items-center gap-2 rounded-lg border {{ $platform->code === 'ai_web_workbench' ? 'border-blue-200 bg-blue-50/60' : 'border-gray-200' }} px-3 py-2 text-sm text-gray-700">
                                        <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" data-geo-diagnosis-platform data-cost="{{ (int) $platform->cost_per_query }}" checked class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">{{ $platform->name }}</span>
                                            <span class="block truncate text-xs text-blue-700">已接入本机搜索软件</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/50 p-3">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <span class="text-sm font-medium text-gray-700">真实 AI 模型</span>
                                    <a href="{{ route('admin.ai-models.index') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">配置模型</a>
                                </div>
                                @if ($realAiModels->isEmpty())
                                    <div class="text-sm text-gray-500">暂无已启用聊天模型</div>
                                @else
                                    <div class="grid gap-2">
                                        @foreach ($realAiModels as $model)
                                            <label class="flex items-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-2 text-sm text-gray-700">
                                                <input type="checkbox" name="platform_codes[]" value="ai_model:{{ (int) $model->id }}" data-geo-diagnosis-platform data-cost="1" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate">{{ $model->name }}</span>
                                                    <span class="block truncate text-xs text-gray-500">{{ $model->model_id }}</span>
                                                </span>
                                                <span class="shrink-0 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">真实</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @if ($mockSearchPlatforms->isNotEmpty())
                                <details class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                    <summary class="cursor-pointer text-xs font-medium text-gray-600">测试备用平台</summary>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @foreach ($mockSearchPlatforms as $platform)
                                            <label class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
                                                <input type="checkbox" name="platform_codes[]" value="{{ $platform->code }}" data-geo-diagnosis-platform data-cost="{{ (int) $platform->cost_per_query }}" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span>{{ $platform->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                        <div>
                            <div class="mb-2 text-sm font-medium text-gray-700">报告模式</div>
                            <div class="grid gap-2">
                                <label class="flex items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                    <input type="radio" name="report_mode" value="with_recommendations" checked class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">内部优化报告</span>
                                        <span class="block text-xs leading-5 text-gray-500">包含优化建议，适合运营和内容团队执行。</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                    <input type="radio" name="report_mode" value="visibility_only" class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">客户可读报告</span>
                                        <span class="block text-xs leading-5 text-gray-500">只展示可见度结论和平台评分，不展示内部优化建议。</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-600" data-geo-diagnosis-summary>
                            已选 0 个关键词 · {{ $activePlatformCount }} 个平台 · 预计 0 点
                        </div>
                        <button type="submit" data-geo-diagnosis-submit data-geo-diagnosis-ready="{{ $diagnosisReady ? '1' : '0' }}" @disabled(! $diagnosisReady) class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <i data-lucide="play" class="h-4 w-4"></i>
                            创建诊断任务
                        </button>
                    </form>
                </section>
            </div>
        </div>
        </div>

        <div data-geo-tab-panel="articles" class="hidden space-y-6">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">GEO 文章管理</h2>
                        <p class="mt-1 text-sm text-gray-500">把引用源、仿写草稿、正式文章、公众号草稿放在同一个链路里。</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('admin.geo.citation-sources.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="link" class="h-4 w-4"></i>
                            引用源采集
                        </a>
                        <a href="{{ route('admin.articles.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="file-text" class="h-4 w-4"></i>
                            正式文章管理
                        </a>
                        <a href="#materials" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="folder-kanban" class="h-4 w-4"></i>
                            补素材
                        </a>
                        <a href="#tasks" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="radar" class="h-4 w-4"></i>
                            发布复测
                        </a>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-4">
                    <div class="rounded-lg border border-blue-100 bg-blue-50/60 p-4">
                        <div class="text-sm text-blue-700">GEO 草稿</div>
                        <div class="mt-2 text-2xl font-semibold text-blue-950">{{ (int) ($geoArticleStats['drafts'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 p-4">
                        <div class="text-sm text-emerald-700">已转文章</div>
                        <div class="mt-2 text-2xl font-semibold text-emerald-950">{{ (int) ($geoArticleStats['converted'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-amber-100 bg-amber-50/70 p-4">
                        <div class="text-sm text-amber-700">待审核文章</div>
                        <div class="mt-2 text-2xl font-semibold text-amber-950">{{ (int) ($geoArticleStats['pending_review'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-violet-100 bg-violet-50/60 p-4">
                        <div class="text-sm text-violet-700">公众号草稿记录</div>
                        <div class="mt-2 text-2xl font-semibold text-violet-950">{{ (int) ($geoArticleStats['publish_records'] ?? 0) }}</div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">草稿到发布链路</h2>
                        <p class="mt-1 text-sm text-gray-500">优先处理最近的 GEO 草稿，必要时回到引用源继续采集。</p>
                    </div>
                    <span class="text-sm text-gray-500">{{ $geoDraftsForWorkspace->count() }} 条</span>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($geoDraftsForWorkspace as $draft)
                        @php
                            $brief = (array) ($draft->writingTask?->brief ?? []);
                            $sourceLabel = $geoSourceLabel((string) ($brief['source'] ?? 'geo_report'));
                            $question = trim((string) ($brief['question'] ?? $draft->seo_title ?? ''));
                            $latestPublishRecord = $draft->publishRecords->first();
                        @endphp
                        <div class="p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate text-sm font-semibold text-gray-900">{{ $draft->title }}</h3>
                                        <span class="rounded-lg bg-cyan-50 px-2 py-1 text-xs font-medium text-cyan-700">{{ $sourceLabel }}</span>
                                        <span class="rounded-lg bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">{{ $draft->status }}</span>
                                    </div>
                                    @if($question !== '')
                                        <div class="mt-2 text-sm text-gray-500">目标问题：{{ \Illuminate\Support\Str::limit($question, 80) }}</div>
                                    @endif
                                    @if($latestPublishRecord)
                                        <div class="mt-2 rounded-lg border border-violet-100 bg-violet-50/60 px-3 py-2 text-sm text-violet-800">
                                            公众号草稿：{{ $publishRecordStatusLabels[$latestPublishRecord->status] ?? $latestPublishRecord->status }}
                                            @if(trim((string) $latestPublishRecord->error_message) !== '')
                                                <span class="ml-1 text-violet-700">{{ \Illuminate\Support\Str::limit((string) $latestPublishRecord->error_message, 100) }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <a href="{{ route('admin.geo.article-drafts.edit', ['draftId' => (int) $draft->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                        <i data-lucide="edit-3" class="h-3.5 w-3.5"></i>
                                        回到草稿
                                    </a>
                                    @if($draft->article)
                                        <a href="{{ route('admin.articles.edit', ['articleId' => (int) $draft->article->id]) }}" class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            <i data-lucide="file-text" class="h-3.5 w-3.5"></i>
                                            编辑正式文章
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('admin.geo.article-drafts.convert', ['draftId' => (int) $draft->id]) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                                                <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                                                转正式文章
                                            </button>
                                        </form>
                                    @endif
                                    <a href="{{ route('admin.geo.citation-sources.index') }}" class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        <i data-lucide="link" class="h-3.5 w-3.5"></i>
                                        继续采集
                                    </a>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-gray-500">暂无 GEO 草稿。可以先从引用源页面生成仿写草稿。</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-gray-900">正式文章回看</h2>
                    <a href="{{ route('admin.articles.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">查看全部文章</a>
                </div>
                <div class="grid gap-3 p-5 lg:grid-cols-2">
                    @forelse ($geoArticlesForWorkspace as $article)
                        @php
                            $metadata = (array) ($article->metadata ?? []);
                            $sourceLabel = $geoSourceLabel((string) ($metadata['source'] ?? 'geo_article'));
                            $draft = $article->geoArticleDrafts->first();
                        @endphp
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900">{{ $article->title }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                        <span>{{ $sourceLabel }}</span>
                                        <span>{{ $article->status }} / {{ $article->review_status }}</span>
                                    </div>
                                </div>
                                <a href="{{ route('admin.articles.edit', ['articleId' => (int) $article->id]) }}" class="shrink-0 text-xs font-medium text-blue-600 hover:text-blue-700">编辑</a>
                            </div>
                            @if($draft)
                                <a href="{{ route('admin.geo.article-drafts.edit', ['draftId' => (int) $draft->id]) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-cyan-700 hover:text-cyan-900">
                                    <i data-lucide="corner-up-left" class="h-3.5 w-3.5"></i>
                                    回到 GEO 草稿
                                </a>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-lg border border-gray-200 px-4 py-8 text-center text-sm text-gray-500 lg:col-span-2">暂无由 GEO 草稿转换的正式文章。</div>
                    @endforelse
                </div>
            </section>
        </div>

        <div data-geo-tab-panel="materials" class="hidden space-y-6">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">GEO 素材管理</h2>
                        <p class="mt-1 text-sm text-gray-500">把文章生产需要的关键词、标题、图片、知识库和作者栏目集中到 GEO 工作台。</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('admin.url-import') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="download" class="h-4 w-4"></i>
                            URL 采集入库
                        </a>
                        <a href="#articles" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="newspaper" class="h-4 w-4"></i>
                            回文章链路
                        </a>
                        <a href="#search" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            回机会搜索
                        </a>
                    </div>
                </div>
                <div class="mt-5 grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                        <div class="text-xs text-gray-500">关键词库</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($geoMaterialStats['keyword_libraries'] ?? 0) }}</div>
                    </a>
                    <a href="{{ route('admin.title-libraries.index') }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                        <div class="text-xs text-gray-500">标题库</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($geoMaterialStats['title_libraries'] ?? 0) }}</div>
                    </a>
                    <a href="{{ route('admin.image-libraries.index') }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                        <div class="text-xs text-gray-500">图库</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($geoMaterialStats['image_libraries'] ?? 0) }}</div>
                    </a>
                    <a href="{{ route('admin.knowledge-bases.index') }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                        <div class="text-xs text-gray-500">知识库</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($geoMaterialStats['knowledge_bases'] ?? 0) }}</div>
                    </a>
                    <a href="{{ route('admin.authors.index') }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                        <div class="text-xs text-gray-500">作者</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($geoMaterialStats['authors'] ?? 0) }}</div>
                    </a>
                    <a href="{{ route('admin.categories.index') }}" class="rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                        <div class="text-xs text-gray-500">栏目</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($geoMaterialStats['categories'] ?? 0) }}</div>
                    </a>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">机会搜索素材沉淀</h2>
                        <p class="mt-1 text-sm text-gray-500">机会搜索出来的关键词、搜索批次和引用源会在这里沉淀，方便继续写文章和补素材。</p>
                    </div>
                    <a href="#search" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        回到机会搜索
                    </a>
                </div>
                <div class="grid gap-6 p-5 xl:grid-cols-3">
                    <div>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">机会词素材</h3>
                            <span class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{{ (int) ($geoMaterialStats['geo_opportunities'] ?? 0) }} 个</span>
                        </div>
                        <div class="space-y-2">
                            @forelse ($geoOpportunityMaterials as $opportunity)
                                <a href="#search" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium text-gray-900">{{ $opportunity->keyword }}</div>
                                            <div class="mt-1 truncate text-xs text-gray-500">{{ $opportunity->cluster_name ?: $opportunity->intent ?: '未分组' }}</div>
                                        </div>
                                        <span class="shrink-0 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">{{ (int) $opportunity->opportunity_score }}</span>
                                    </div>
                                    @if(trim((string) $opportunity->rationale) !== '')
                                        <div class="mt-2 line-clamp-2 text-xs leading-5 text-gray-500">{{ \Illuminate\Support\Str::limit((string) $opportunity->rationale, 72) }}</div>
                                    @endif
                                </a>
                            @empty
                                <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无机会词素材，先到机会搜索生成。</div>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">真实搜索素材</h3>
                            <span class="rounded-lg bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700">{{ (int) ($geoMaterialStats['geo_search_runs'] ?? 0) }} 批</span>
                        </div>
                        <div class="space-y-2">
                            @forelse ($geoSearchRunMaterials as $run)
                                <a href="#search" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                    <div class="truncate text-sm font-medium text-gray-900">{{ $run->name }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                        <span>{{ $searchRunStatusLabels[$run->status] ?? $run->status }}</span>
                                        <span>问题 {{ (int) $run->total_questions }}</span>
                                        <span>均分 {{ (int) $run->average_score }}</span>
                                    </div>
                                </a>
                            @empty
                                <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无真实搜索批次。</div>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">引用源素材</h3>
                            <span class="rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">{{ (int) ($geoMaterialStats['geo_citation_sources'] ?? 0) }} 个</span>
                        </div>
                        <div class="space-y-2">
                            @forelse ($geoCitationSourceMaterials as $source)
                                <a href="{{ route('admin.geo.citation-sources.show', ['sourceId' => (int) $source->id]) }}" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium text-gray-900">{{ $source->title ?: ($source->domain ?: '未命名引用源') }}</div>
                                            <div class="mt-1 truncate text-xs text-gray-500">{{ $source->domain ?: $source->url }}</div>
                                        </div>
                                        @if($source->latestPageSnapshot?->latestScore)
                                            <span class="shrink-0 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">{{ (int) $source->latestPageSnapshot->latestScore->total_score }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                        <span>{{ $citationSourceStatusLabels[$source->status] ?? $source->status }}</span>
                                        <span>引用 {{ (int) $source->citation_count }}</span>
                                        @if($source->platform_name)
                                            <span>{{ $source->platform_name }}</span>
                                        @endif
                                    </div>
                                </a>
                            @empty
                                <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无引用源素材。</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-base font-semibold text-gray-900">关键词与标题素材</h2>
                            <a href="{{ route('admin.materials.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">素材总览</a>
                        </div>
                    </div>
                    <div class="grid gap-4 p-5 md:grid-cols-2">
                        <div>
                            <div class="mb-3 text-sm font-medium text-gray-700">关键词库</div>
                            <div class="space-y-2">
                                @forelse (collect($geoMaterialWorkspace['keyword_libraries'] ?? []) as $library)
                                    <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                        <div class="truncate text-sm font-medium text-gray-900">{{ $library->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ (int) ($library->keyword_count ?? 0) }} 个词</div>
                                    </a>
                                @empty
                                    <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无关键词库</div>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <div class="mb-3 text-sm font-medium text-gray-700">标题库</div>
                            <div class="space-y-2">
                                @forelse (collect($geoMaterialWorkspace['title_libraries'] ?? []) as $library)
                                    <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                        <div class="truncate text-sm font-medium text-gray-900">{{ $library->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ (int) ($library->title_count ?? 0) }} 个标题</div>
                                    </a>
                                @empty
                                    <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无标题库</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">图片与知识素材</h2>
                    </div>
                    <div class="grid gap-4 p-5 md:grid-cols-2">
                        <div>
                            <div class="mb-3 text-sm font-medium text-gray-700">图库</div>
                            <div class="space-y-2">
                                @forelse (collect($geoMaterialWorkspace['image_libraries'] ?? []) as $library)
                                    <a href="{{ route('admin.image-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                        <div class="truncate text-sm font-medium text-gray-900">{{ $library->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ (int) ($library->image_count ?? 0) }} 张图</div>
                                    </a>
                                @empty
                                    <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无图库</div>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <div class="mb-3 text-sm font-medium text-gray-700">知识库</div>
                            <div class="space-y-2">
                                @forelse (collect($geoMaterialWorkspace['knowledge_bases'] ?? []) as $library)
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $library->id]) }}" class="block rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                                        <div class="truncate text-sm font-medium text-gray-900">{{ $library->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ (int) ($library->character_count ?? 0) }} 字符</div>
                                    </a>
                                @empty
                                    <div class="rounded-lg border border-gray-200 px-3 py-6 text-center text-sm text-gray-500">暂无知识库</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div data-geo-tab-panel="tasks" class="hidden space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">最近诊断任务</h2>
                    <p class="mt-1 text-sm text-gray-500">诊断报告、文章生成、发布后复测会在这里收口。</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500">{{ $tasks->count() }} 条</span>
                    <a href="#setup" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                        新建诊断
                    </a>
                    <a href="#articles" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="newspaper" class="h-3.5 w-3.5"></i>
                        文章链路
                    </a>
                    <a href="{{ route('admin.tasks.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                        <i data-lucide="list-todo" class="h-3.5 w-3.5"></i>
                        任务管理
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">任务</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">问题数</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">得分</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">点数</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">报告</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">创建时间</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($tasks as $task)
                            <tr>
                                <td class="px-5 py-4 text-sm font-medium text-gray-900">{{ $task->name }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ $statusLabels[$task->status] ?? $task->status }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ (int) $task->questions_count }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ $task->status === 'completed' ? (int) $task->total_score : '—' }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">{{ (int) $task->points_cost }}</td>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    @if ($task->report)
                                        <div class="max-w-xs">
                                            <div class="font-medium text-gray-900">{{ $task->report->title }}</div>
                                            <div class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $task->report->summary }}</div>
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-500">{{ $task->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4 text-sm">
                                    @if (in_array($task->status, ['pending', 'failed'], true))
                                        <form method="POST" action="{{ route('admin.geo.diagnosis.run', ['taskId' => (int) $task->id]) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                <i data-lucide="play" class="h-3.5 w-3.5"></i>
                                                执行诊断
                                            </button>
                                        </form>
                                    @elseif ($task->report)
                                        <a href="{{ route('admin.geo.reports.show', ['taskId' => (int) $task->id]) }}" class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                                            <i data-lucide="file-check-2" class="h-3.5 w-3.5"></i>
                                            查看报告
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-sm text-gray-500">暂无诊断任务</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const buttons = Array.from(document.querySelectorAll('[data-geo-tab-target]'));
            const panels = Array.from(document.querySelectorAll('[data-geo-tab-panel]'));

            if (!buttons.length || !panels.length) {
                return;
            }

            const panelIds = new Set(panels.map((panel) => panel.dataset.geoTabPanel));
            const activate = (tabId, shouldUpdateHash = true) => {
                const nextTabId = panelIds.has(tabId) ? tabId : 'overview';

                buttons.forEach((button) => {
                    const isActive = button.dataset.geoTabTarget === nextTabId;
                    button.classList.toggle('border-blue-500', isActive);
                    button.classList.toggle('text-blue-700', isActive);
                    button.classList.toggle('font-semibold', isActive);
                    button.classList.toggle('border-transparent', !isActive);
                    button.classList.toggle('text-gray-600', !isActive);
                    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

                    const icon = button.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('text-blue-600', isActive);
                        icon.classList.toggle('text-slate-400', !isActive);
                    }
                });

                panels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.dataset.geoTabPanel !== nextTabId);
                });

                if (shouldUpdateHash) {
                    history.replaceState(null, '', `#${nextTabId}`);
                }

                if (window.lucide) {
                    window.lucide.createIcons();
                }
            };

            buttons.forEach((button) => {
                button.addEventListener('click', () => activate(button.dataset.geoTabTarget));
            });

            window.addEventListener('hashchange', () => activate(window.location.hash.slice(1), false));

            activate(window.location.hash.slice(1) || 'overview', false);

            const inspectionNameInput = document.getElementById('external_inspection_name');
            const inspectionQuestionsInput = document.getElementById('external_questions_text');
            const inspectionTargetInput = document.getElementById('external_target_keyword_hit_rate');
            const customInspectionPanel = document.querySelector('[data-geo-custom-inspection]');

            document.querySelectorAll('[data-geo-inspection-preset]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (inspectionNameInput) {
                        inspectionNameInput.value = button.dataset.presetName || '';
                        inspectionNameInput.focus({ preventScroll: true });
                    }

                    if (inspectionQuestionsInput) {
                        inspectionQuestionsInput.value = button.dataset.presetQuestions || '';
                    }

                    if (inspectionTargetInput && button.dataset.presetTarget) {
                        inspectionTargetInput.value = button.dataset.presetTarget;
                    }

                    if (customInspectionPanel) {
                        customInspectionPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });

            document.querySelectorAll('[data-geo-run-form]').forEach((form) => {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('[data-geo-run-submit]');
                    if (! button) {
                        return;
                    }

                    button.disabled = true;
                    button.classList.add('opacity-80');

                    const label = button.querySelector('[data-geo-run-label]');
                    if (label) {
                        label.textContent = button.dataset.runningLabel || '运行中...';
                    }
                });
            });

            const workbenchPlatformModule = document.querySelector('[data-geo-workbench-platform-module]');
            const refreshWorkbenchIcons = () => {
                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                }
            };
            const showWorkbenchModuleMessage = (message, isError = false) => {
                const messageBox = workbenchPlatformModule?.querySelector('[data-geo-workbench-module-message]');
                if (! messageBox || ! message) {
                    return;
                }

                messageBox.textContent = message;
                messageBox.classList.remove('hidden', 'border-blue-100', 'bg-blue-50', 'text-blue-700', 'border-red-100', 'bg-red-50', 'text-red-700');
                messageBox.classList.add(
                    isError ? 'border-red-100' : 'border-blue-100',
                    isError ? 'bg-red-50' : 'bg-blue-50',
                    isError ? 'text-red-700' : 'text-blue-700'
                );
            };
            const updateWorkbenchModule = (html, message = '', isError = false) => {
                if (! workbenchPlatformModule || ! html) {
                    return;
                }

                workbenchPlatformModule.innerHTML = html;
                refreshWorkbenchIcons();
                showWorkbenchModuleMessage(message, isError);
            };
            const refreshWorkbenchPlatformModule = async (message = '') => {
                if (! workbenchPlatformModule?.dataset.statusUrl) {
                    return;
                }

                const response = await fetch(workbenchPlatformModule.dataset.statusUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json();
                updateWorkbenchModule(data.html, message || data.message || '', ! data.ok);
            };
            const scheduleWorkbenchPlatformRefresh = () => {
                [1800, 5000, 10000].forEach((delay) => {
                    window.setTimeout(() => {
                        refreshWorkbenchPlatformModule().catch(() => {});
                    }, delay);
                });
            };
            const setWorkbenchButtonBusy = (button, busy) => {
                if (! button) {
                    return;
                }

                button.disabled = busy;
                button.classList.toggle('opacity-80', busy);
                button.classList.toggle('cursor-wait', busy);

                const label = button.querySelector('[data-geo-workbench-label]');
                if (! label) {
                    return;
                }

                if (busy) {
                    button.dataset.originalLabel = label.textContent || '';
                    label.textContent = button.dataset.busyLabel || '处理中...';
                } else if (button.dataset.originalLabel) {
                    label.textContent = button.dataset.originalLabel;
                }
            };

            ['geo-web-workbench-open-login-form', 'geo-web-workbench-check-logins-form'].forEach((formId) => {
                const form = document.getElementById(formId);
                if (! form) {
                    return;
                }

                form.addEventListener('submit', async (event) => {
                    const button = event.submitter || document.querySelector(`[form="${formId}"][data-geo-workbench-action]`);
                    if (! form.hasAttribute('data-geo-workbench-async')) {
                        return;
                    }

                    event.preventDefault();
                    setWorkbenchButtonBusy(button, true);

                    try {
                        const formData = new FormData(form);
                        if (button?.name) {
                            formData.set(button.name, button.value || '');
                        }
                        const response = await fetch(form.action, {
                            method: form.method || 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: formData,
                        });
                        const data = await response.json();
                        updateWorkbenchModule(data.html, data.message || '', ! response.ok || ! data.ok);
                        scheduleWorkbenchPlatformRefresh();
                    } catch (error) {
                        showWorkbenchModuleMessage(error.message || '操作失败，请稍后重试', true);
                    } finally {
                        setWorkbenchButtonBusy(button, false);
                    }
                });
            });

            const searchRunDeleteMessage = document.querySelector('[data-geo-search-run-delete-message]');
            const showSearchRunDeleteMessage = (message, isError = false) => {
                if (! searchRunDeleteMessage || ! message) {
                    return;
                }

                searchRunDeleteMessage.textContent = message;
                searchRunDeleteMessage.classList.remove('hidden', 'border-blue-100', 'bg-blue-50', 'text-blue-700', 'border-red-100', 'bg-red-50', 'text-red-700');
                searchRunDeleteMessage.classList.add(
                    isError ? 'border-red-100' : 'border-blue-100',
                    isError ? 'bg-red-50' : 'bg-blue-50',
                    isError ? 'text-red-700' : 'text-blue-700'
                );
            };
            const setSearchRunDeleteBusy = (button, busy) => {
                if (! button) {
                    return;
                }

                button.disabled = busy;
                button.classList.toggle('opacity-80', busy);
                button.classList.toggle('cursor-wait', busy);

                const label = button.querySelector('[data-geo-search-run-delete-label]');
                if (! label) {
                    return;
                }

                if (busy) {
                    button.dataset.originalLabel = label.textContent || '';
                    label.textContent = button.dataset.deletingLabel || '删除中...';
                } else if (button.dataset.originalLabel) {
                    label.textContent = button.dataset.originalLabel;
                }
            };

            document.querySelectorAll('[data-geo-search-run-delete-form]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    if (event.defaultPrevented) {
                        return;
                    }

                    event.preventDefault();

                    const button = event.submitter || form.querySelector('[data-geo-search-run-delete-submit]');
                    setSearchRunDeleteBusy(button, true);

                    try {
                        const response = await fetch(form.action, {
                            method: form.method || 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(form),
                        });
                        const contentType = response.headers.get('content-type') || '';
                        const data = contentType.includes('application/json')
                            ? await response.json()
                            : { ok: false, message: '删除失败，请刷新后重试' };

                        if (! response.ok || data.ok === false) {
                            throw new Error(data.message || '删除失败，请刷新后重试');
                        }

                        const deletedRunIds = Array.isArray(data.deleted_run_ids) && data.deleted_run_ids.length > 0
                            ? data.deleted_run_ids
                            : [data.run_id];
                        deletedRunIds.forEach((runId) => {
                            document.querySelector(`[data-geo-search-run-card="${runId}"]`)?.remove();
                        });
                        showSearchRunDeleteMessage(data.message || '检视批次已删除');
                    } catch (error) {
                        showSearchRunDeleteMessage(error.message || '删除失败，请刷新后重试', true);
                        setSearchRunDeleteBusy(button, false);
                    }
                });
            });

            const keywordCheckboxes = Array.from(document.querySelectorAll('[data-geo-diagnosis-keyword]'));
            const platformCheckboxes = Array.from(document.querySelectorAll('[data-geo-diagnosis-platform]'));
            const diagnosisSummary = document.querySelector('[data-geo-diagnosis-summary]');
            const diagnosisSubmit = document.querySelector('[data-geo-diagnosis-submit]');

            const updateDiagnosisSummary = () => {
                const selectedKeywords = keywordCheckboxes.filter((checkbox) => checkbox.checked).length;
                const selectedPlatforms = platformCheckboxes.filter((checkbox) => checkbox.checked);
                const platformCost = selectedPlatforms.reduce((total, checkbox) => {
                    const cost = Number.parseInt(checkbox.dataset.cost || '1', 10);

                    return total + (Number.isNaN(cost) ? 1 : cost);
                }, 0);
                const estimatedCost = selectedKeywords * platformCost;

                if (diagnosisSummary) {
                    diagnosisSummary.textContent = `已选 ${selectedKeywords} 个关键词 · ${selectedPlatforms.length} 个平台 · 预计 ${estimatedCost} 点`;
                }

                if (diagnosisSubmit) {
                    const isReady = diagnosisSubmit.dataset.geoDiagnosisReady === '1';
                    diagnosisSubmit.disabled = !isReady || selectedKeywords === 0 || selectedPlatforms.length === 0;
                }
            };

            keywordCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateDiagnosisSummary));
            platformCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateDiagnosisSummary));

            document.querySelectorAll('[data-geo-select-keywords]').forEach((button) => {
                button.addEventListener('click', () => {
                    const shouldSelect = button.dataset.geoSelectKeywords === 'all';
                    keywordCheckboxes.forEach((checkbox) => {
                        checkbox.checked = shouldSelect;
                    });
                    updateDiagnosisSummary();
                });
            });

            updateDiagnosisSummary();
        });
    </script>
@endpush
