@extends('admin.layouts.app')

@section('content')
    @php
        $statusStyles = [
            'ready' => 'bg-emerald-100 text-emerald-700',
            'running' => 'bg-blue-100 text-blue-700',
            'warning' => 'bg-amber-100 text-amber-700',
            'error' => 'bg-red-100 text-red-700',
            'available' => 'bg-violet-100 text-violet-700',
        ];

        $toneStyles = [
            'blue' => 'bg-blue-50 text-blue-600',
            'green' => 'bg-emerald-50 text-emerald-600',
            'amber' => 'bg-amber-50 text-amber-600',
            'red' => 'bg-red-50 text-red-600',
            'violet' => 'bg-violet-50 text-violet-600',
            'slate' => 'bg-slate-100 text-slate-700',
        ];

        $stepNumberStyles = [
            'blue' => 'text-blue-600',
            'green' => 'text-emerald-600',
            'amber' => 'text-amber-600',
            'red' => 'text-red-600',
            'violet' => 'text-violet-600',
            'slate' => 'text-slate-600',
        ];

        $quickMaterialLinks = [
            ['label' => __('admin.dashboard.quick_start.knowledge'), 'href' => route('admin.knowledge-bases.index'), 'class' => 'border-orange-100 bg-orange-50 text-orange-700 hover:bg-orange-100'],
            ['label' => __('admin.dashboard.quick_start.titles'), 'href' => route('admin.title-libraries.index'), 'class' => 'border-green-100 bg-green-50 text-green-700 hover:bg-green-100'],
            ['label' => __('admin.dashboard.quick_start.keywords'), 'href' => route('admin.keyword-libraries.index'), 'class' => 'border-blue-100 bg-blue-50 text-blue-700 hover:bg-blue-100'],
            ['label' => __('admin.dashboard.quick_start.images'), 'href' => route('admin.image-libraries.index'), 'class' => 'border-purple-100 bg-purple-50 text-purple-700 hover:bg-purple-100'],
            ['label' => __('admin.dashboard.quick_start.authors'), 'href' => route('admin.authors.index'), 'class' => 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100'],
        ];

        $demoJourney = [
            [
                'step' => '01',
                'title' => __('admin.dashboard.demo_journey.assets_title'),
                'desc' => __('admin.dashboard.demo_journey.assets_desc'),
                'href' => route('admin.materials.index'),
                'icon' => 'database',
                'tone' => 'green',
            ],
            [
                'step' => '02',
                'title' => __('admin.dashboard.demo_journey.task_title'),
                'desc' => __('admin.dashboard.demo_journey.task_desc'),
                'href' => route('admin.tasks.create'),
                'icon' => 'workflow',
                'tone' => 'blue',
            ],
            [
                'step' => '03',
                'title' => __('admin.dashboard.demo_journey.quality_title'),
                'desc' => __('admin.dashboard.demo_journey.quality_desc'),
                'href' => route('admin.articles.index'),
                'icon' => 'shield-check',
                'tone' => 'amber',
            ],
            [
                'step' => '04',
                'title' => __('admin.dashboard.demo_journey.distribution_title'),
                'desc' => __('admin.dashboard.demo_journey.distribution_desc'),
                'href' => route('admin.distribution.index'),
                'icon' => 'radio-tower',
                'tone' => 'violet',
            ],
            [
                'step' => '05',
                'title' => __('admin.dashboard.demo_journey.observation_title'),
                'desc' => __('admin.dashboard.demo_journey.observation_desc'),
                'href' => route('admin.analytics'),
                'icon' => 'chart-no-axes-combined',
                'tone' => 'slate',
            ],
        ];

        $stats = $dashboardStats ?? [];
        $todayStats = $dashboardTodayStats ?? [];
        $tasks = $taskHealth ?? [];
        $materials = $materialHealth ?? [];
        $ai = $aiHealth ?? [];
        $distribution = $distributionHealth ?? [];
        $urlImport = $urlImportHealth ?? [];

        $totalArticles = (int) ($stats['total_articles'] ?? 0);
        $publishedArticles = (int) ($stats['published_articles'] ?? 0);
        $draftArticles = (int) ($stats['draft_articles'] ?? 0);
        $pendingReview = (int) ($stats['pending_review'] ?? 0);
        $totalTasks = (int) ($stats['total_tasks'] ?? 0);
        $activeTasks = (int) ($tasks['active_tasks'] ?? $stats['active_tasks'] ?? 0);
        $runningJobs = (int) ($tasks['running_jobs'] ?? $stats['running_jobs'] ?? 0);
        $pendingJobs = (int) ($tasks['pending_jobs'] ?? $stats['pending_jobs'] ?? 0);
        $failedJobs = (int) ($tasks['failed_jobs'] ?? $stats['failed_jobs'] ?? 0);
        $chatModels = (int) ($ai['chat_models'] ?? 0);
        $embeddingModels = (int) ($ai['embedding_models'] ?? 0);
        $aiUsedToday = (int) ($ai['used_today'] ?? 0);
        $materialLibraryCount = (int) ($materials['keyword_libraries'] ?? 0)
            + (int) ($materials['title_libraries'] ?? 0)
            + (int) ($materials['knowledge_bases'] ?? 0)
            + (int) ($materials['image_libraries'] ?? 0)
            + (int) ($materials['authors'] ?? 0);
        $knowledgeChunks = (int) ($materials['knowledge_chunks'] ?? 0);
        $vectorizedChunks = (int) ($materials['vectorized_chunks'] ?? 0);
        $unvectorizedChunks = (int) ($materials['unvectorized_chunks'] ?? 0);
        $totalPrompts = (int) ($stats['total_prompts'] ?? 0);
        $bodyPrompts = (int) ($stats['body_prompts'] ?? 0);
        $specialPrompts = (int) ($stats['special_prompts'] ?? 0);
        $channelsTotal = (int) ($distribution['channels_total'] ?? 0);
        $channelsActive = (int) ($distribution['channels_active'] ?? 0);
        $distributionPending = (int) ($distribution['pending'] ?? 0) + (int) ($distribution['sending'] ?? 0);
        $distributionFailed = (int) ($distribution['failed'] ?? 0);
        $distributionSynced = (int) ($distribution['synced'] ?? 0);
        $distributionTotal = (int) ($distribution['total'] ?? 0);
        $urlImportFailed = (int) ($urlImport['failed'] ?? 0);
        $todayArticles = (int) ($todayStats['today_articles'] ?? 0);
        $todayVisits = (int) ($todayStats['today_views'] ?? 0);
        $aiBotCount = (int) ($todayStats['today_ai_bot_views'] ?? 0);
        $riskCount = $failedJobs + $distributionFailed + $urlImportFailed;

        $materialsStatus = $unvectorizedChunks > 0 ? 'warning' : ($materialLibraryCount > 0 ? 'ready' : 'available');
        $promptStatus = $totalPrompts > 0 ? 'ready' : 'warning';
        $taskStatus = $failedJobs > 0 ? 'error' : (($runningJobs + $pendingJobs) > 0 ? 'running' : ($activeTasks > 0 ? 'ready' : 'available'));
        $contentLibraryStatus = $pendingReview > 0 ? 'warning' : ($totalArticles > 0 ? 'ready' : 'available');
        $reviewStatus = $pendingReview > 0 ? 'warning' : 'ready';
        $distributionStatus = $distributionFailed > 0 ? 'error' : ($distributionPending > 0 ? 'warning' : ($channelsActive > 0 ? 'ready' : 'available'));
        $feedbackStatus = $todayVisits > 0 ? 'running' : 'available';
        $runningBadgeCount = (int) (($runningJobs + $pendingJobs) > 0)
            + (int) ((int) ($distribution['sending'] ?? 0) > 0)
            + (int) ($todayArticles > 0);
        $attentionBadgeCount = (int) ($failedJobs > 0)
            + (int) ($unvectorizedChunks > 0)
            + (int) ($pendingReview > 0)
            + (int) ($distributionFailed > 0)
            + (int) ($urlImportFailed > 0);

        $flowNodes = [
            [
                'title' => __('admin.dashboard.automation.node_prompt_graph_title'),
                'desc' => __('admin.dashboard.automation.node_prompt_graph_desc'),
                'icon' => 'map',
                'tone' => 'blue',
                'status' => $promptStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_body_prompts', ['count' => $bodyPrompts]),
                    __('admin.dashboard.automation.metric_special_prompts', ['count' => $specialPrompts]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.navigation.body_prompt_label'), 'href' => route('admin.ai-prompts'), 'primary' => false],
                    ['label' => __('admin.dashboard.navigation.special_prompt_label'), 'href' => route('admin.ai-special-prompts'), 'primary' => false],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_knowledge_assets_title'),
                'desc' => __('admin.dashboard.automation.node_knowledge_assets_desc'),
                'icon' => 'database',
                'tone' => 'green',
                'status' => $materialsStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_materials', ['count' => $materialLibraryCount]),
                    __('admin.dashboard.automation.metric_vectorized', ['done' => $vectorizedChunks, 'total' => $knowledgeChunks]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.automation.action_refresh_chunks'), 'href' => route('admin.knowledge-bases.index'), 'primary' => false, 'warning' => true],
                    ['label' => __('admin.dashboard.automation.action_view'), 'href' => route('admin.materials.index'), 'primary' => false],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_evidence_structure_title'),
                'desc' => __('admin.dashboard.automation.node_evidence_structure_desc'),
                'icon' => 'blocks',
                'tone' => 'amber',
                'status' => $materialsStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_vectorized', ['done' => $vectorizedChunks, 'total' => $knowledgeChunks]),
                    __('admin.dashboard.automation.metric_unvectorized', ['count' => $unvectorizedChunks]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.automation.action_refresh_chunks'), 'href' => route('admin.knowledge-bases.index'), 'primary' => false, 'warning' => true],
                    ['label' => __('admin.dashboard.automation.action_view'), 'href' => route('admin.materials.index'), 'primary' => false],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_engineering_task_title'),
                'desc' => __('admin.dashboard.automation.node_engineering_task_desc'),
                'icon' => 'workflow',
                'tone' => 'blue',
                'status' => $taskStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_enabled', ['count' => $activeTasks]),
                    __('admin.dashboard.automation.metric_queued', ['count' => $pendingJobs]),
                    __('admin.dashboard.automation.metric_failed', ['count' => $failedJobs]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.quick_start.task_button'), 'href' => route('admin.tasks.create'), 'primary' => true],
                    ['label' => __('admin.dashboard.automation.action_queue'), 'href' => route('admin.tasks.index'), 'primary' => false],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_content_title'),
                'desc' => __('admin.dashboard.automation.node_content_desc'),
                'icon' => 'file-pen-line',
                'tone' => 'violet',
                'status' => $contentLibraryStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_drafts', ['count' => $draftArticles]),
                    __('admin.dashboard.automation.metric_today_new', ['count' => $todayArticles]),
                    __('admin.dashboard.automation.metric_published', ['count' => $publishedArticles]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.automation.action_articles'), 'href' => route('admin.articles.index'), 'primary' => true],
                    ['label' => __('admin.dashboard.automation.action_review'), 'href' => route('admin.articles.index', ['review_status' => 'pending']), 'primary' => false, 'warning' => true],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_quality_gate_title'),
                'desc' => __('admin.dashboard.automation.node_quality_gate_desc'),
                'icon' => 'badge-check',
                'tone' => 'amber',
                'status' => $reviewStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_review_pending', ['count' => $pendingReview]),
                    __('admin.dashboard.automation.metric_published', ['count' => $publishedArticles]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.automation.action_review'), 'href' => route('admin.articles.index'), 'primary' => false, 'warning' => true],
                    ['label' => __('admin.dashboard.automation.action_publish'), 'href' => route('admin.articles.index'), 'primary' => false],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_authority_distribution_title'),
                'desc' => __('admin.dashboard.automation.node_authority_distribution_desc'),
                'icon' => 'radio-tower',
                'tone' => 'red',
                'status' => $distributionStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_channels', ['count' => $channelsTotal]),
                    __('admin.dashboard.automation.metric_failed', ['count' => $distributionFailed]),
                    __('admin.dashboard.automation.metric_pending_distribution', ['count' => $distributionPending]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.automation.action_handle_failed'), 'href' => route('admin.distribution.jobs'), 'primary' => false, 'warning' => true],
                    ['label' => __('admin.dashboard.automation.action_channels'), 'href' => route('admin.distribution.index'), 'primary' => false],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.node_measurement_title'),
                'desc' => __('admin.dashboard.automation.node_measurement_desc'),
                'icon' => 'chart-no-axes-combined',
                'tone' => 'violet',
                'status' => $feedbackStatus,
                'metrics' => [
                    __('admin.dashboard.automation.metric_today_visits', ['count' => $todayVisits]),
                    __('admin.dashboard.automation.metric_ai_bots', ['count' => $aiBotCount]),
                    __('admin.dashboard.automation.metric_ai_today', ['count' => $aiUsedToday]),
                ],
                'actions' => [
                    ['label' => __('admin.dashboard.navigation.analytics_title'), 'href' => route('admin.analytics'), 'primary' => true],
                ],
            ],
        ];

        $recommendations = [
            [
                'title' => __('admin.dashboard.automation.rec_distribution_title'),
                'desc' => __('admin.dashboard.automation.rec_distribution_desc'),
                'count' => $distributionFailed,
                'icon' => 'triangle-alert',
                'style' => 'border-red-200 bg-red-50',
                'badge' => 'error',
                'href' => route('admin.distribution.jobs'),
                'button' => __('admin.dashboard.navigation.distribution_jobs_title'),
                'buttonStyle' => 'border-orange-200 bg-orange-50 text-orange-700 hover:bg-orange-100',
            ],
            [
                'title' => __('admin.dashboard.automation.rec_chunks_title'),
                'desc' => __('admin.dashboard.automation.rec_chunks_desc'),
                'count' => $unvectorizedChunks,
                'icon' => 'database-zap',
                'style' => 'border-amber-200 bg-amber-50',
                'badge' => 'warning',
                'href' => route('admin.knowledge-bases.index'),
                'button' => __('admin.dashboard.automation.action_refresh_chunks'),
                'buttonStyle' => 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
            ],
            [
                'title' => __('admin.dashboard.automation.rec_review_title'),
                'desc' => __('admin.dashboard.automation.rec_review_desc'),
                'count' => $pendingReview,
                'icon' => 'badge-check',
                'style' => 'border-blue-200 bg-blue-50',
                'badge' => 'running',
                'href' => route('admin.articles.index'),
                'button' => __('admin.dashboard.automation.action_review'),
                'buttonStyle' => 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700',
            ],
        ];
        $activeRecommendations = array_values(array_filter(
            $recommendations,
            static fn (array $recommendation): bool => (int) $recommendation['count'] > 0
        ));

        $healthCards = [
            [
                'title' => __('admin.dashboard.automation.health_task_title'),
                'value' => $runningJobs.' / '.$totalTasks,
                'meta' => __('admin.dashboard.automation.health_task_meta', ['running' => $runningJobs, 'queued' => $pendingJobs, 'failed' => $failedJobs]),
                'icon' => 'activity',
                'tone' => $failedJobs > 0 ? 'red' : 'amber',
            ],
            [
                'title' => __('admin.dashboard.automation.health_content_title'),
                'value' => (string) $totalArticles,
                'meta' => __('admin.dashboard.automation.health_content_meta', ['published' => $publishedArticles, 'drafts' => $draftArticles, 'pending' => $pendingReview]),
                'icon' => 'file-text',
                'tone' => 'blue',
            ],
            [
                'title' => __('admin.dashboard.automation.health_distribution_title'),
                'value' => (string) $channelsActive,
                'meta' => __('admin.dashboard.automation.health_distribution_meta', ['active' => $channelsActive, 'pending' => $distributionPending, 'failed' => $distributionFailed]),
                'icon' => 'radio-tower',
                'tone' => $distributionFailed > 0 ? 'red' : 'green',
            ],
            [
                'title' => __('admin.dashboard.automation.health_feedback_title'),
                'value' => (string) $todayVisits,
                'meta' => __('admin.dashboard.automation.health_feedback_meta', ['visits' => $todayVisits, 'calls' => $aiUsedToday]),
                'icon' => 'bar-chart-3',
                'tone' => 'violet',
            ],
        ];

        $lanes = [
            [
                'title' => __('admin.dashboard.automation.lane_single_title'),
                'desc' => __('admin.dashboard.automation.lane_single_desc'),
                'rows' => [
                    ['title' => __('admin.dashboard.navigation.ai_config_title'), 'desc' => __('admin.dashboard.automation.lane_ai_desc'), 'href' => route('admin.ai-models.index'), 'icon' => 'cpu', 'count' => $chatModels + $embeddingModels],
                    ['title' => __('admin.dashboard.navigation.materials_title'), 'desc' => __('admin.dashboard.automation.lane_material_desc'), 'href' => route('admin.materials.index'), 'icon' => 'database', 'count' => $materialLibraryCount],
                    ['title' => __('admin.dashboard.navigation.create_task_title'), 'desc' => __('admin.dashboard.navigation.create_task_desc'), 'href' => route('admin.tasks.create'), 'icon' => 'plus-circle', 'count' => $totalTasks],
                    ['title' => __('admin.dashboard.navigation.articles_title'), 'desc' => __('admin.dashboard.automation.lane_articles_desc'), 'href' => route('admin.articles.index'), 'icon' => 'file-text', 'count' => $totalArticles],
                    ['title' => __('admin.dashboard.navigation.prompt_config_title'), 'desc' => __('admin.dashboard.navigation.prompt_config_desc'), 'href' => route('admin.ai-prompts'), 'icon' => 'message-square-text', 'count' => $totalPrompts],
                    ['title' => __('admin.dashboard.navigation.site_settings_title'), 'desc' => __('admin.dashboard.navigation.site_settings_desc'), 'href' => route('admin.site-settings.index'), 'icon' => 'settings', 'count' => 'SEO'],
                    ['title' => __('admin.dashboard.navigation.admin_users_title'), 'desc' => __('admin.dashboard.navigation.admin_users_desc'), 'href' => route('admin.admin-users.index'), 'icon' => 'users', 'count' => 'Admin'],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.lane_multi_title'),
                'desc' => __('admin.dashboard.automation.lane_multi_desc'),
                'rows' => [
                    ['title' => __('admin.dashboard.navigation.distribution_channels_title'), 'desc' => __('admin.dashboard.navigation.distribution_channels_desc'), 'href' => route('admin.distribution.index'), 'icon' => 'radio-tower', 'count' => $channelsTotal],
                    ['title' => __('admin.dashboard.navigation.create_channel_title'), 'desc' => __('admin.dashboard.automation.lane_channel_desc'), 'href' => route('admin.distribution.create'), 'icon' => 'square-plus', 'count' => $channelsActive],
                    ['title' => __('admin.dashboard.navigation.distribution_jobs_title'), 'desc' => __('admin.dashboard.automation.lane_queue_desc'), 'href' => route('admin.distribution.jobs'), 'icon' => 'list-checks', 'count' => $distributionPending],
                    ['title' => __('admin.dashboard.navigation.remote_content_title'), 'desc' => __('admin.dashboard.automation.lane_remote_desc'), 'href' => route('admin.distribution.index'), 'icon' => 'file-pen-line', 'count' => $distributionSynced.'/'.$distributionTotal],
                ],
            ],
            [
                'title' => __('admin.dashboard.automation.lane_feedback_title'),
                'desc' => __('admin.dashboard.automation.lane_feedback_desc'),
                'rows' => [
                    ['title' => __('admin.dashboard.navigation.analytics_title'), 'desc' => __('admin.dashboard.automation.lane_analytics_desc'), 'href' => route('admin.analytics'), 'icon' => 'chart-no-axes-combined', 'count' => $todayVisits],
                    ['title' => __('admin.dashboard.automation.lane_ai_bot_title'), 'desc' => __('admin.dashboard.automation.lane_ai_bot_desc'), 'href' => route('admin.analytics'), 'icon' => 'bot', 'count' => $aiBotCount],
                    ['title' => __('admin.dashboard.automation.lane_risk_title'), 'desc' => __('admin.dashboard.automation.lane_risk_desc'), 'href' => route('admin.analytics'), 'icon' => 'triangle-alert', 'count' => $riskCount],
                ],
            ],
        ];

        $skillResourceCards = [
            [
                'title' => __('admin.dashboard.skill_resources.template_title'),
                'desc' => __('admin.dashboard.skill_resources.template_desc'),
                'href' => 'https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-template',
                'icon' => 'layers-3',
                'tone' => 'blue',
            ],
            [
                'title' => __('admin.dashboard.skill_resources.design_title'),
                'desc' => __('admin.dashboard.skill_resources.design_desc'),
                'href' => 'https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-design',
                'icon' => 'palette',
                'tone' => 'violet',
            ],
            [
                'title' => __('admin.dashboard.skill_resources.cli_title'),
                'desc' => __('admin.dashboard.skill_resources.cli_desc'),
                'href' => 'https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-cli',
                'icon' => 'terminal',
                'tone' => 'slate',
            ],
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.dashboard.navigation.heading') }}</h1>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.dashboard.navigation.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.dashboard') }}" class="inline-flex h-10 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.dashboard.refresh') }}
                </a>
                <a href="{{ route('admin.tasks.create') }}" class="inline-flex h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.dashboard.quick_start.task_button') }}
                </a>
            </div>
        </div>

        <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ __('admin.dashboard.quick_start.eyebrow') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.title') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.subtitle') }}</p>
                </div>
                <span class="inline-flex w-fit items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                    <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                    {{ __('admin.dashboard.automation.basic_ready') }}
                </span>
            </div>

            <div class="grid grid-cols-1 divide-y divide-gray-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">1</div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.api_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.api_desc') }}</p>
                            <a href="{{ route('admin.ai-models.index') }}" class="mt-4 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="plug-zap" class="mr-1.5 h-4 w-4"></i>
                                {{ __('admin.dashboard.quick_start.api_button') }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white">2</div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.material_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.material_desc') }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($quickMaterialLinks as $link)
                                    <a href="{{ $link['href'] }}" class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-medium {{ $link['class'] }}">
                                        {{ $link['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">3</div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.task_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.task_desc') }}</p>
                            <a href="{{ route('admin.tasks.create') }}" class="mt-4 inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                                {{ __('admin.dashboard.quick_start.task_button') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ __('admin.dashboard.demo_journey.eyebrow') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900">{{ __('admin.dashboard.demo_journey.title') }}</h2>
                    <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-500">{{ __('admin.dashboard.demo_journey.desc') }}</p>
                </div>
                <span class="inline-flex w-fit items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                    <i data-lucide="presentation" class="mr-1.5 h-3.5 w-3.5"></i>
                    {{ __('admin.dashboard.demo_journey.badge') }}
                </span>
            </div>
            <div class="grid grid-cols-1 divide-y divide-gray-100 lg:grid-cols-5 lg:divide-x lg:divide-y-0">
                @foreach ($demoJourney as $journeyItem)
                    @php($toneClass = $toneStyles[$journeyItem['tone']] ?? $toneStyles['slate'])
                    <article class="flex min-h-[220px] flex-col p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $toneClass }}">
                                <i data-lucide="{{ $journeyItem['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">{{ $journeyItem['step'] }}</span>
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-900">{{ $journeyItem['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-500">{{ $journeyItem['desc'] }}</p>
                        <a href="{{ $journeyItem['href'] }}" class="mt-auto inline-flex h-9 w-fit items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            {{ __('admin.dashboard.demo_journey.open') }}
                            <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4"></i>
                        </a>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.dashboard.automation.title') }}</h2>
                    <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-500">{{ __('admin.dashboard.automation.desc') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                        <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                        {{ __('admin.dashboard.automation.running_badge', ['count' => $runningBadgeCount]) }}
                    </span>
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                        <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                        {{ __('admin.dashboard.automation.attention_badge', ['count' => $attentionBadgeCount]) }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 p-5 2xl:grid-cols-[minmax(0,1fr)_360px]">
                <div class="min-w-0">
                    <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.automation.flow_title') }}</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.automation.flow_desc') }}</p>
                        </div>
                        <a href="{{ route('admin.site-settings.index') }}" class="inline-flex h-9 w-fit items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.dashboard.automation.automation_settings') }}
                        </a>
                    </div>

                    <div class="relative grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="pointer-events-none absolute left-[8%] right-[8%] top-[42px] hidden h-0.5 bg-gradient-to-r from-blue-200 via-emerald-200 to-red-200 xl:block"></div>
                        @foreach ($flowNodes as $node)
                            @php($stepNumber = str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT))
                            @php($statusClass = $statusStyles[$node['status']] ?? $statusStyles['ready'])
                            @php($toneClass = $toneStyles[$node['tone']] ?? $toneStyles['slate'])
                            @php($stepNumberClass = $stepNumberStyles[$node['tone']] ?? $stepNumberStyles['slate'])
                            <article id="content-engineering-step-{{ $stepNumber }}" class="relative z-10 flex min-h-[178px] scroll-mt-24 flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm" aria-label="{{ __('admin.dashboard.automation.step_anchor', ['step' => $stepNumber, 'title' => $node['title']]) }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <span class="shrink-0 text-2xl font-semibold leading-10 tracking-wide tabular-nums {{ $stepNumberClass }}">{{ $stepNumber }}</span>
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $toneClass }}">
                                            <i data-lucide="{{ $node['icon'] }}" class="h-5 w-5"></i>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                                        <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-current"></span>
                                        {{ __('admin.dashboard.automation.status_'.$node['status']) }}
                                    </span>
                                </div>
                                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('admin.dashboard.automation.step_label', ['step' => $stepNumber]) }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $node['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-500">{{ $node['desc'] }}</p>
                                <div class="mt-auto flex flex-wrap gap-2 pt-4">
                                    @foreach ($node['metrics'] as $metric)
                                        <span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-600">{{ $metric }}</span>
                                    @endforeach
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($node['actions'] as $action)
                                        <a href="{{ $action['href'] }}" class="inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold {{ ! empty($action['primary']) ? 'bg-blue-600 text-white hover:bg-blue-700' : (! empty($action['warning']) ? 'border border-orange-200 bg-orange-50 text-orange-700 hover:bg-orange-100' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50') }}">
                                            {{ $action['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                <aside class="flex flex-col gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.automation.recommendations_title') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.automation.recommendations_desc') }}</p>
                    </div>
                    @forelse ($activeRecommendations as $recommendation)
                        @php($badgeClass = $statusStyles[$recommendation['badge']] ?? $statusStyles['warning'])
                        <div class="rounded-lg border p-4 {{ $recommendation['style'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-2">
                                    <i data-lucide="{{ $recommendation['icon'] }}" class="h-4 w-4 shrink-0 text-gray-700"></i>
                                    <h3 class="truncate text-sm font-semibold text-gray-900">{{ $recommendation['title'] }}</h3>
                                </div>
                                <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">{{ $recommendation['count'] }}</span>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-gray-600">{{ $recommendation['desc'] }}</p>
                            <a href="{{ $recommendation['href'] }}" class="mt-3 inline-flex h-9 items-center rounded-lg border px-3 text-sm font-semibold {{ $recommendation['buttonStyle'] }}">
                                {{ $recommendation['button'] }}
                            </a>
                        </div>
                    @empty
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                            <div class="flex items-center gap-2">
                                <i data-lucide="circle-check" class="h-4 w-4 text-emerald-700"></i>
                                <h3 class="text-sm font-semibold text-emerald-900">{{ __('admin.dashboard.automation.basic_ready') }}</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-emerald-800">{{ __('admin.dashboard.automation.recommendations_empty') }}</p>
                        </div>
                    @endforelse
                </aside>
            </div>
        </section>

        <section class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($healthCards as $card)
                @php($toneClass = $toneStyles[$card['tone']] ?? $toneStyles['slate'])
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">{{ $card['title'] }}</h3>
                        <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5 {{ str_replace('bg-', 'text-', explode(' ', $toneClass)[1] ?? 'text-gray-600') }}"></i>
                    </div>
                    <div class="mt-5 text-3xl font-bold text-gray-900">{{ $card['value'] }}</div>
                    <div class="mt-2 text-sm font-medium text-gray-500">{{ $card['meta'] }}</div>
                </div>
            @endforeach
        </section>

        <section class="mb-8 grid grid-cols-1 gap-5 xl:grid-cols-3">
            @foreach ($lanes as $lane)
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $lane['title'] }}</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-500">{{ $lane['desc'] }}</p>
                    <div class="mt-5 grid gap-3">
                        @foreach ($lane['rows'] as $row)
                            <a href="{{ $row['href'] }}" class="grid grid-cols-[26px_minmax(0,1fr)_auto] items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 p-3 transition hover:border-blue-100 hover:bg-blue-50">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500">
                                    <i data-lucide="{{ $row['icon'] }}" class="h-4 w-4"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-gray-900">{{ $row['title'] }}</span>
                                    <span class="mt-1 block truncate text-xs text-gray-500">{{ $row['desc'] }}</span>
                                </span>
                                <span class="whitespace-nowrap text-sm font-bold text-gray-900">{{ $row['count'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        <section>
            <div class="mb-5">
                <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.dashboard.skill_resources.title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.dashboard.skill_resources.desc') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                @foreach ($skillResourceCards as $card)
                    @php($toneClass = $toneStyles[$card['tone']] ?? $toneStyles['slate'])
                    <a href="{{ $card['href'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200 transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $toneClass }}">
                                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-900">{{ $card['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-500">{{ $card['desc'] }}</p>
                                <span class="mt-4 inline-flex items-center text-sm font-medium text-blue-600">
                                    {{ __('admin.dashboard.skill_resources.open') }}
                                    <i data-lucide="external-link" class="ml-1.5 h-4 w-4"></i>
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
@endsection
