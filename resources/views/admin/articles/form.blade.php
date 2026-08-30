@extends('admin.layouts.app')

@php
    $i18nRoot = $isEdit ? 'admin.article_edit' : 'admin.article_create';
    $formAction = $isEdit
        ? route('admin.articles.update', ['articleId' => (int) $articleId])
        : route('admin.articles.store');
    $articleImageUploadUrl = $isEdit
        ? \App\Support\AdminWeb::routePath('admin.articles.editor.images.upload', ['articleId' => (int) $articleId])
        : '';
    $articleWechatHtmlUrl = \App\Support\AdminWeb::routePath('admin.articles.editor.wechat-html');
    $vditorLocaleMap = [
        'zh_CN' => 'zh_CN',
        'en' => 'en_US',
        'en_US' => 'en_US',
        'ja' => 'ja_JP',
        'ja_JP' => 'ja_JP',
        'ru' => 'ru_RU',
        'ru_RU' => 'ru_RU',
        'pt_BR' => 'pt_BR',
        'es' => 'es_ES',
        'es_ES' => 'es_ES',
    ];
    $vditorLang = $vditorLocaleMap[str_replace('-', '_', app()->getLocale())] ?? 'en_US';
    $editorQuickActions = [
        ['key' => 'image', 'icon' => 'image', 'label' => __('admin.article_editor.quick_actions.image')],
        ['key' => 'heading', 'icon' => 'heading-2', 'label' => __('admin.article_editor.quick_actions.heading')],
        ['key' => 'quote', 'icon' => 'quote', 'label' => __('admin.article_editor.quick_actions.quote')],
        ['key' => 'list', 'icon' => 'list', 'label' => __('admin.article_editor.quick_actions.list')],
        ['key' => 'divider', 'icon' => 'minus', 'label' => __('admin.article_editor.quick_actions.divider')],
    ];
    $articleAssistantMessages = [
        'titleLoadFailed' => __('admin.article_assistant.title_picker.load_failed'),
        'titleSummary' => __('admin.article_assistant.title_picker.summary'),
        'titleUsed' => __('admin.article_assistant.title_picker.used_count'),
        'titleAi' => __('admin.article_assistant.title_picker.ai_label'),
        'titleKeyword' => __('admin.article_assistant.title_picker.keyword_label'),
        'titleNoKeyword' => __('admin.article_assistant.title_picker.no_keyword'),
        'titleNoSelection' => __('admin.article_assistant.title_picker.no_selection'),
        'titleSelected' => __('admin.article_assistant.title_picker.selected'),
        'generateButton' => __('admin.article_assistant.generate.button'),
        'stopButton' => __('admin.article_assistant.generate.stop'),
        'titleRequired' => __('admin.article_assistant.generate.title_required'),
        'knowledgeRequired' => __('admin.article_assistant.generate.knowledge_required'),
        'promptRequired' => __('admin.article_assistant.generate.prompt_required'),
        'modelRequired' => __('admin.article_assistant.generate.model_required'),
        'replaceConfirm' => __('admin.article_assistant.generate.replace_confirm'),
        'preparing' => __('admin.article_assistant.generate.preparing'),
        'streaming' => __('admin.article_assistant.generate.streaming'),
        'characters' => __('admin.article_assistant.generate.characters'),
        'completed' => __('admin.article_assistant.generate.completed'),
        'stopped' => __('admin.article_assistant.generate.stopped'),
        'failed' => __('admin.article_assistant.generate.failed'),
        'emptyContent' => __('admin.article_assistant.generate.empty_content'),
        'networkFailed' => __('admin.article_assistant.generate.network_failed'),
    ];
    $articleAssistantMessagesJson = json_encode(
        $articleAssistantMessages,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $formData = [
        'title' => old('title', (string) ($articleForm['title'] ?? '')),
        'excerpt' => old('excerpt', (string) ($articleForm['excerpt'] ?? '')),
        'content' => old('content', (string) ($articleForm['content'] ?? '')),
        'keywords' => old('keywords', (string) ($articleForm['keywords'] ?? '')),
        'meta_description' => old('meta_description', (string) ($articleForm['meta_description'] ?? '')),
        'status' => old('status', (string) ($articleForm['status'] ?? 'draft')),
        'review_status' => old('review_status', (string) ($articleForm['review_status'] ?? 'pending')),
        'category_id' => old('category_id', (string) ($articleForm['category_id'] ?? '')),
        'author_id' => old('author_id', (string) ($articleForm['author_id'] ?? '')),
        'slug' => (string) ($articleForm['slug'] ?? ''),
        'published_at' => (string) ($articleForm['published_at'] ?? ''),
        'task_name' => (string) ($articleForm['task_name'] ?? ''),
        'is_hot' => old('is_hot', !empty($articleForm['is_hot']) ? '1' : '0'),
        'is_featured' => old('is_featured', !empty($articleForm['is_featured']) ? '1' : '0'),
        'source_title_id' => old('source_title_id', ''),
        'is_ai_generated' => old('is_ai_generated', !empty($articleForm['is_ai_generated']) ? '1' : '0'),
    ];
    $qualityChecks = [
        'has_title' => trim((string) $formData['title']) !== '',
        'has_excerpt' => trim((string) $formData['excerpt']) !== '',
        'has_content' => trim((string) $formData['content']) !== '',
        'has_keywords' => trim((string) $formData['keywords']) !== '',
        'has_meta_description' => trim((string) $formData['meta_description']) !== '',
        'is_published' => $formData['status'] === 'published',
        'is_reviewed' => in_array($formData['review_status'], ['approved', 'auto_approved'], true),
        'has_category' => trim((string) $formData['category_id']) !== '',
        'has_author' => trim((string) $formData['author_id']) !== '',
        'has_source_task' => trim((string) $formData['task_name']) !== '',
    ];
    $riskDisplayStatus = ! $isEdit
        ? 'unscanned'
        : (($riskScan['state'] ?? null) === 'stale' ? 'stale' : (string) ($riskScan['status'] ?? 'unscanned'));
    $riskStatusPresentation = [
        'clean' => ['label' => __('admin.articles.quality_scorecard.risk_status_clean'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'icon' => 'shield-check'],
        'warning' => ['label' => __('admin.articles.quality_scorecard.risk_status_warning'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100', 'icon' => 'shield-alert'],
        'blocked' => ['label' => __('admin.articles.quality_scorecard.risk_status_blocked'), 'class' => 'bg-red-50 text-red-700 ring-red-100', 'icon' => 'shield-x'],
        'stale' => ['label' => __('admin.articles.quality_scorecard.risk_status_stale'), 'class' => 'bg-slate-100 text-slate-700 ring-slate-200', 'icon' => 'refresh-cw'],
        'unscanned' => ['label' => __('admin.articles.quality_scorecard.risk_status_unscanned'), 'class' => 'bg-slate-100 text-slate-600 ring-slate-200', 'icon' => 'scan-search'],
    ][$riskDisplayStatus];
    $aiQualityEnabled = $isEdit && (bool) ($articleForm['ai_quality_enabled'] ?? false);
    $aiQualityStatus = (string) ($aiQualityCheck?->status ?? ($aiQualityEnabled ? 'not_started' : 'disabled'));
    $aiQualityDecision = (string) ($aiQualityCheck?->decision ?? '');
    $aiQualityProgressData = is_array($aiQualityProgress ?? null) ? $aiQualityProgress : [];
    $aiQualityProgressPercent = max(0, min(100, (int) ($aiQualityProgressData['progress_percent'] ?? 0)));
    $aiQualityWorkflowApply = is_array($aiQualityProgressData['workflow_apply'] ?? null) ? $aiQualityProgressData['workflow_apply'] : [];
    $aiQualityWorkflowApplyStatus = (string) ($aiQualityWorkflowApply['status'] ?? '');
    $aiQualityIsSampled = (string) ($aiQualityCheck?->inspection_scope ?? 'full') === 'fallback_sampled';
    $aiQualityCoverage = is_array($aiQualityCheck?->coverage_meta) ? $aiQualityCheck->coverage_meta : [];
    $aiQualityPresentation = match (true) {
        ! $aiQualityEnabled => ['label' => __('admin.articles.ai_quality.disabled_short'), 'class' => 'bg-gray-100 text-gray-600 ring-gray-200', 'panel' => 'border-gray-200 bg-gray-50', 'icon' => 'shield-off'],
        $aiQualityStatus === 'not_started' => ['label' => __('admin.articles.ai_quality.not_started'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100', 'panel' => 'border-amber-200 bg-amber-50/40', 'icon' => 'circle-dashed'],
        in_array($aiQualityStatus, ['queued', 'running'], true) => ['label' => $aiQualityIsSampled ? __('admin.articles.ai_quality.sampled_in_progress_label') : __('admin.articles.ai_quality.pending'), 'class' => 'bg-sky-50 text-sky-700 ring-sky-100', 'panel' => 'border-sky-200 bg-sky-50/40', 'icon' => 'loader-circle'],
        $aiQualityStatus === 'stale' => ['label' => __('admin.articles.ai_quality.stale'), 'class' => 'bg-slate-100 text-slate-700 ring-slate-200', 'panel' => 'border-slate-200 bg-slate-50', 'icon' => 'refresh-cw'],
        $aiQualityStatus === 'failed' || $aiQualityDecision === 'error' => ['label' => __('admin.articles.ai_quality.failed'), 'class' => 'bg-red-50 text-red-700 ring-red-100', 'panel' => 'border-red-200 bg-red-50/40', 'icon' => 'triangle-alert'],
        $aiQualityDecision === 'passed' => ['label' => $aiQualityIsSampled ? __('admin.articles.ai_quality.sampled_passed_label') : __('admin.articles.ai_quality.passed'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'panel' => 'border-emerald-200 bg-emerald-50/40', 'icon' => 'shield-check'],
        $aiQualityDecision === 'needs_review' && (bool) $aiQualityCheck?->is_overridden => ['label' => __('admin.articles.ai_quality.overridden'), 'class' => 'bg-blue-50 text-blue-700 ring-blue-100', 'panel' => 'border-blue-200 bg-blue-50/40', 'icon' => 'user-check'],
        $aiQualityDecision === 'needs_review' => ['label' => __('admin.articles.ai_quality.needs_review'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100', 'panel' => 'border-amber-200 bg-amber-50/40', 'icon' => 'user-round-check'],
        default => ['label' => __('admin.articles.ai_quality.blocked'), 'class' => 'bg-red-50 text-red-700 ring-red-100', 'panel' => 'border-red-200 bg-red-50/40', 'icon' => 'shield-x'],
    };
    $aiQualityDimensionWeights = [
        'knowledge_consistency' => 35,
        'data_traceability' => 25,
        'advertising_compliance' => 30,
        'content_integrity' => 10,
    ];
    $aiQualityIssues = is_array($aiQualityCheck?->issues) ? $aiQualityCheck->issues : [];
    $aiQualityUncertainties = is_array($aiQualityCheck?->uncertainties) ? $aiQualityCheck->uncertainties : [];
    $aiOptimizationData = is_array($aiOptimization ?? null) ? $aiOptimization : null;
    $aiOptimizationFeatureEnabled = (bool) config('geoflow.ai_quality_optimization_enabled', false);
    $hasValidFullAiQuality = $aiQualityCheck
        && (string) $aiQualityCheck->status === 'completed'
        && (string) $aiQualityCheck->inspection_scope === 'full';
    $qualityFieldChecks = [
        [
            'label' => __('admin.articles.quality_scorecard.check_excerpt'),
            'passed' => $qualityChecks['has_excerpt'],
            'passText' => __('admin.articles.quality_scorecard.check_excerpt_pass'),
            'pendingText' => __('admin.articles.quality_scorecard.check_excerpt_pending'),
        ],
        [
            'label' => __('admin.articles.quality_scorecard.check_seo'),
            'passed' => $qualityChecks['has_meta_description'],
            'passText' => __('admin.articles.quality_scorecard.check_seo_pass'),
            'pendingText' => __('admin.articles.quality_scorecard.check_seo_pending'),
        ],
        [
            'label' => __('admin.articles.quality_scorecard.check_publish'),
            'passed' => $qualityChecks['is_published'],
            'passText' => __('admin.articles.quality_scorecard.check_publish_pass'),
            'pendingText' => __('admin.articles.quality_scorecard.check_publish_pending'),
        ],
        [
            'label' => __('admin.articles.quality_scorecard.check_review'),
            'passed' => $qualityChecks['is_reviewed'],
            'passText' => __('admin.articles.quality_scorecard.check_review_pass'),
            'pendingText' => __('admin.articles.quality_scorecard.check_review_pending'),
        ],
        [
            'label' => __('admin.articles.quality_scorecard.check_source'),
            'passed' => $qualityChecks['has_source_task'],
            'passText' => __('admin.articles.quality_scorecard.check_source_pass'),
            'pendingText' => __('admin.articles.quality_scorecard.check_source_pending'),
        ],
    ];
    $qualityScorecard = [
        [
            'title' => __('admin.articles.quality_scorecard.structure_title'),
            'desc' => __('admin.articles.quality_scorecard.structure_desc'),
            'icon' => 'layout-template',
            'class' => 'bg-blue-50 text-blue-600 ring-blue-100',
            'passed' => $qualityChecks['has_title'] && $qualityChecks['has_excerpt'] && $qualityChecks['has_content'],
        ],
        [
            'title' => __('admin.articles.quality_scorecard.evidence_title'),
            'desc' => __('admin.articles.quality_scorecard.evidence_desc'),
            'icon' => 'database',
            'class' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
            'passed' => $qualityChecks['has_keywords'] || $qualityChecks['has_source_task'],
        ],
        [
            'title' => __('admin.articles.quality_scorecard.risk_title'),
            'desc' => __('admin.articles.quality_scorecard.risk_desc'),
            'icon' => 'shield-alert',
            'class' => 'bg-amber-50 text-amber-600 ring-amber-100',
            'passed' => $riskDisplayStatus === 'clean' || ($riskDisplayStatus === 'warning' && ! empty($riskScan['is_overridden'])),
            'status_label' => $riskStatusPresentation['label'],
            'status_class' => $riskStatusPresentation['class'],
            'status_icon' => $riskStatusPresentation['icon'],
        ],
        [
            'title' => __('admin.articles.quality_scorecard.attribution_title'),
            'desc' => __('admin.articles.quality_scorecard.attribution_desc'),
            'icon' => 'git-branch',
            'class' => 'bg-violet-50 text-violet-600 ring-violet-100',
            'passed' => $qualityChecks['has_category'] && $qualityChecks['has_author'] && $qualityChecks['has_source_task'],
        ],
        [
            'title' => __('admin.articles.quality_scorecard.distribution_title'),
            'desc' => __('admin.articles.quality_scorecard.distribution_desc'),
            'icon' => 'radio-tower',
            'class' => 'bg-slate-100 text-slate-700 ring-slate-200',
            'passed' => $qualityChecks['is_published'] && $qualityChecks['has_meta_description'],
        ],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center space-x-4 mb-6">
            <a href="{{ route('admin.articles.index') }}" aria-label="{{ __('admin.common.back') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __($i18nRoot.'.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">
                    @if($isEdit)
                        {{ $formData['title'] }}
                    @else
                        {{ __($i18nRoot.'.page_subtitle') }}
                    @endif
                </p>
            </div>
        </div>

        <form id="article-edit-form" method="POST" action="{{ $formAction }}" class="space-y-8">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="lg:col-span-3 space-y-6">
                    <section class="rounded-lg border border-emerald-100 bg-emerald-50/70 p-5">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white text-emerald-600 ring-1 ring-emerald-100">
                                <i data-lucide="shield-check" class="h-5 w-5"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">{{ __('admin.articles.quality_gate.form_title') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.articles.quality_gate.form_desc') }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ([
                                        __('admin.articles.quality_gate.form_structure'),
                                        __('admin.articles.quality_gate.form_evidence'),
                                        __('admin.articles.quality_gate.form_risk'),
                                        __('admin.articles.quality_gate.form_publish'),
                                    ] as $item)
                                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </section>

                    @if($isEdit)
                        <section
                            id="ai-quality-result"
                            data-ai-quality-collapsible
                            data-collapsed="false"
                            class="scroll-mt-24 overflow-hidden rounded-lg border {{ $aiQualityPresentation['panel'] }} shadow-sm"
                        >
                            <div data-ai-quality-collapse-header class="border-b border-current/10 bg-white/80 px-6 py-5">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-blue-600 ring-1 ring-blue-100">
                                            <i data-lucide="scan-search" class="h-5 w-5"></i>
                                        </div>
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.articles.ai_quality.title') }}</h3>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $aiQualityPresentation['class'] }}">
                                                    <i data-lucide="{{ $aiQualityPresentation['icon'] }}" class="mr-1.5 h-3.5 w-3.5"></i>
                                                    <span data-ai-quality-result-label>{{ $aiQualityPresentation['label'] }}</span>
                                                </span>
                                            </div>
                                            <div data-ai-quality-expanded-copy>
                                                <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.articles.ai_quality.desc') }}</p>
                                                @if($aiQualityCheck)
                                                    <p class="mt-2 text-xs text-gray-500">
                                                        {{ __('admin.articles.ai_quality.prompt_model', [
                                                            'prompt' => $aiQualityCheck->prompt->name ?? '#'.$aiQualityCheck->prompt_id,
                                                            'model' => $aiQualityCheck->aiModel->name ?? '#'.$aiQualityCheck->ai_model_id,
                                                        ]) }}
                                                        @if($aiQualityCheck->finished_at)
                                                            · {{ __('admin.articles.ai_quality.finished_at', ['time' => $aiQualityCheck->finished_at->format('Y-m-d H:i')]) }}
                                                        @endif
                                                    </p>
                                                @endif
                                            </div>
                                            <div data-ai-quality-compact-summary hidden class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600">
                                                @if(in_array($aiQualityStatus, ['queued', 'running'], true))
                                                    <span data-ai-quality-compact-progress class="font-mono font-semibold tabular-nums text-sky-700">{{ $aiQualityProgressPercent }}%</span>
                                                    <span aria-hidden="true" class="text-gray-300">·</span>
                                                    <span data-ai-quality-compact-message>{{ $aiQualityProgressData['message'] ?? __('admin.articles.ai_quality.progress_queued') }}</span>
                                                @elseif($aiQualityCheck?->score !== null)
                                                    <span class="font-semibold text-gray-800">{{ $aiQualityIsSampled ? __('admin.articles.ai_quality.sampled_score_label') : __('admin.articles.ai_quality.score') }} <span class="font-mono tabular-nums">{{ (int) $aiQualityCheck->score }}</span></span>
                                                    <span aria-hidden="true" class="text-gray-300">·</span>
                                                    <span>{{ __('admin.articles.ai_quality.issue_count', ['count' => count($aiQualityIssues)]) }}</span>
                                                    @if($aiQualityCheck->finished_at)
                                                        <span aria-hidden="true" class="hidden text-gray-300 xl:inline">·</span>
                                                        <span class="hidden xl:inline">{{ __('admin.articles.ai_quality.finished_at', ['time' => $aiQualityCheck->finished_at->format('Y-m-d H:i')]) }}</span>
                                                    @endif
                                                @elseif($aiQualityCheck?->finished_at)
                                                    <span>{{ __('admin.articles.ai_quality.finished_at', ['time' => $aiQualityCheck->finished_at->format('Y-m-d H:i')]) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            data-ai-optimization-open
                                            @disabled(! $aiOptimizationFeatureEnabled || (string) $formData['status'] !== 'draft')
                                            class="inline-flex items-center justify-center rounded-md border border-blue-200 bg-blue-50 px-3.5 py-2 text-xs font-semibold text-blue-700 hover:border-blue-300 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400"
                                        >
                                            <i data-lucide="wand-sparkles" class="mr-1.5 h-4 w-4"></i>
                                            {{ $hasValidFullAiQuality ? __('admin.articles.ai_optimization.start') : __('admin.articles.ai_optimization.inspect_and_start') }}
                                        </button>
                                        <button
                                            type="submit"
                                            form="article-edit-form"
                                            name="run_ai_quality_after_save"
                                            value="1"
                                            data-ai-quality-submit
                                            @disabled($aiQualityCheck && in_array($aiQualityStatus, ['queued', 'running'], true))
                                            class="inline-flex items-center justify-center rounded-md bg-gray-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 active:translate-y-px disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600"
                                        >
                                            <i data-lucide="{{ $aiQualityCheck ? 'refresh-cw' : 'sparkles' }}" class="mr-1.5 h-4 w-4"></i>
                                            {{ $aiQualityCheck ? __('admin.articles.ai_quality.recheck') : __('admin.articles.ai_quality.start_manual') }}
                                        </button>
                                        <button
                                            type="button"
                                            data-ai-quality-collapse-toggle
                                            data-collapse-label="{{ __('admin.articles.ai_quality.collapse') }}"
                                            data-expand-label="{{ __('admin.articles.ai_quality.expand') }}"
                                            aria-controls="ai-quality-result-content"
                                            aria-expanded="true"
                                            aria-label="{{ __('admin.articles.ai_quality.collapse') }}"
                                            title="{{ __('admin.articles.ai_quality.collapse') }}"
                                            class="inline-flex items-center justify-center rounded-md border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-600 transition-[background-color,color,transform] duration-150 hover:bg-gray-50 hover:text-gray-900 active:scale-[.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                        >
                                            <i data-lucide="chevron-up" data-ai-quality-collapse-icon aria-hidden="true" class="mr-1.5 h-4 w-4 transition-transform duration-150"></i>
                                            <span data-ai-quality-collapse-label>{{ __('admin.articles.ai_quality.collapse') }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div id="ai-quality-result-content" data-ai-quality-collapse-body>
                            <div
                                data-ai-optimization-panel
                                data-start-url="{{ \App\Support\AdminWeb::routePath('admin.articles.ai-quality.optimization.store', ['articleId' => (int) $articleId]) }}"
                                data-status-url="{{ \App\Support\AdminWeb::routePath('admin.articles.ai-quality.status', ['articleId' => (int) $articleId]) }}"
                                data-model-required="{{ empty($formData['task_name']) ? 'true' : 'false' }}"
                                data-feature-enabled="{{ $aiOptimizationFeatureEnabled ? 'true' : 'false' }}"
                                class="hidden border-b border-blue-100 bg-blue-50/60 px-6 py-5"
                            >
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">{{ __('admin.articles.ai_optimization.panel_title') }}</p>
                                        <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.articles.ai_optimization.panel_help') }}</p>
                                    </div>
                                    <div class="grid shrink-0 grid-cols-1 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('admin.articles.ai_optimization.level_label') }}">
                                        @foreach (['pass', 'excellent_80', 'excellent_90'] as $level)
                                            <label class="cursor-pointer rounded-md border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-800">
                                                <input type="radio" name="article_ai_optimization_level" value="{{ $level }}" @checked($level === 'excellent_80') class="mr-1.5 border-gray-300 text-blue-600 focus:ring-blue-500">
                                                {{ __('admin.articles.ai_optimization.level_'.$level) }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                @if(empty($formData['task_name']))
                                    <div class="mt-4 max-w-md">
                                        <label for="article-ai-optimization-model" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.generate.model_label') }}</label>
                                        <div class="relative mt-1">
                                            <select id="article-ai-optimization-model" class="block w-full appearance-none truncate rounded-md border-gray-300 bg-white py-2 pl-3 pr-10 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="">{{ __('admin.article_assistant.generate.model_placeholder') }}</option>
                                                @foreach(($formOptions['ai_models'] ?? []) as $modelOption)
                                                    <option value="{{ $modelOption['id'] }}">{{ $modelOption['name'] }}@if($modelOption['model_id'] !== '') · {{ $modelOption['model_id'] }}@endif</option>
                                                @endforeach
                                            </select>
                                            <i data-lucide="chevron-down" aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"></i>
                                        </div>
                                    </div>
                                @endif
                                <p data-ai-optimization-notice class="mt-4 rounded-md bg-white px-3 py-2 text-xs leading-5 text-gray-600 ring-1 ring-blue-100" aria-live="polite">
                                    {{ $aiOptimizationFeatureEnabled ? __('admin.articles.ai_optimization.ready') : __('admin.articles.ai_optimization.feature_disabled') }}
                                </p>
                                <div data-ai-optimization-progress class="mt-4 hidden">
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span data-ai-optimization-status class="font-semibold text-blue-900"></span>
                                        <span data-ai-optimization-rounds class="text-xs text-blue-700"></span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-blue-100">
                                        <div data-ai-optimization-progress-bar class="h-full rounded-full bg-blue-600" style="width: 0%"></div>
                                    </div>
                                </div>
                                <div data-ai-optimization-candidate class="mt-4 hidden rounded-lg border border-gray-200 bg-white p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-semibold text-gray-900">{{ __('admin.articles.ai_optimization.candidate_title') }}</p>
                                        <p data-ai-optimization-score class="text-xs font-medium text-blue-700"></p>
                                    </div>
                                    <div data-ai-optimization-modifications class="mt-3 grid gap-3"></div>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button type="button" data-ai-optimization-start class="inline-flex min-h-10 items-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-blue-300">
                                        <i data-lucide="wand-sparkles" class="mr-2 h-4 w-4"></i>{{ __('admin.articles.ai_optimization.start_action') }}
                                    </button>
                                    <button type="button" data-ai-optimization-apply class="hidden inline-flex min-h-10 items-center rounded-md bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-emerald-300">
                                        <i data-lucide="check" class="mr-2 h-4 w-4"></i>{{ __('admin.articles.ai_optimization.apply_action') }}
                                    </button>
                                    <button type="button" data-ai-optimization-cancel class="hidden inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                        {{ __('admin.articles.ai_optimization.cancel_active_action') }}
                                    </button>
                                    <button type="button" data-ai-optimization-rollback class="hidden inline-flex min-h-10 items-center rounded-md border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-800 hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400">
                                        {{ __('admin.articles.ai_optimization.rollback_action') }}
                                    </button>
                                    <button type="button" data-ai-optimization-close class="inline-flex min-h-10 items-center rounded-md px-3 text-sm font-semibold text-gray-600 hover:bg-white hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                        {{ __('admin.articles.ai_optimization.manual_action') }}
                                    </button>
                                </div>
                                @php
                                    $aiOptimizationI18n = [
                                        'dirty' => __('admin.articles.ai_optimization.dirty'),
                                        'modelRequired' => __('admin.articles.ai_optimization.model_required'),
                                        'requestFailed' => __('admin.articles.ai_optimization.request_failed'),
                                        'score' => __('admin.articles.ai_optimization.score_change', ['before' => '__BEFORE__', 'after' => '__AFTER__']),
                                        'rounds' => __('admin.articles.ai_optimization.round_progress', ['current' => '__CURRENT__', 'total' => '__TOTAL__']),
                                        'field' => __('admin.articles.ai_optimization.field_change', ['field' => '__FIELD__', 'round' => '__ROUND__']),
                                        'before' => __('admin.articles.ai_optimization.before'),
                                        'after' => __('admin.articles.ai_optimization.after'),
                                        'cancelActive' => __('admin.articles.ai_optimization.cancel_active_action'),
                                        'discardCandidate' => __('admin.articles.ai_optimization.discard_candidate_action'),
                                        'stateTargetScoreReview' => __('admin.articles.ai_optimization.state_target_score_review'),
                                        'actionErrorTitle' => __('admin.action_dialog.error_title'),
                                        'actionErrorGuidance' => __('admin.action_dialog.error_guidance'),
                                        'closeLabel' => __('admin.action_dialog.close'),
                                        'dialogs' => [
                                            'startTitle' => __('admin.action_dialog.article_ai_optimization.start_title'),
                                            'startMessage' => __('admin.action_dialog.article_ai_optimization.start_message'),
                                            'startGuidance' => __('admin.action_dialog.article_ai_optimization.start_guidance'),
                                            'startLabel' => __('admin.action_dialog.article_ai_optimization.start_label'),
                                            'applyTitle' => __('admin.action_dialog.article_ai_optimization.apply_title'),
                                            'applyMessage' => __('admin.action_dialog.article_ai_optimization.apply_message'),
                                            'applyGuidance' => __('admin.action_dialog.article_ai_optimization.apply_guidance'),
                                            'applyLabel' => __('admin.action_dialog.article_ai_optimization.apply_label'),
                                            'cancelTitle' => __('admin.action_dialog.article_ai_optimization.cancel_title'),
                                            'cancelMessage' => __('admin.action_dialog.article_ai_optimization.cancel_message'),
                                            'cancelGuidance' => __('admin.action_dialog.article_ai_optimization.cancel_guidance'),
                                            'cancelLabel' => __('admin.action_dialog.article_ai_optimization.cancel_label'),
                                            'discardTitle' => __('admin.action_dialog.article_ai_optimization.discard_title'),
                                            'discardMessage' => __('admin.action_dialog.article_ai_optimization.discard_message'),
                                            'discardLabel' => __('admin.action_dialog.article_ai_optimization.discard_label'),
                                            'rollbackTitle' => __('admin.action_dialog.article_ai_optimization.rollback_title'),
                                            'rollbackMessage' => __('admin.action_dialog.article_ai_optimization.rollback_message'),
                                            'rollbackGuidance' => __('admin.action_dialog.article_ai_optimization.rollback_guidance'),
                                            'rollbackLabel' => __('admin.action_dialog.article_ai_optimization.rollback_label'),
                                            'qualityTitle' => __('admin.action_dialog.article_ai_quality.run_title'),
                                            'qualityMessage' => __('admin.action_dialog.article_ai_quality.run_message'),
                                            'qualityGuidance' => __('admin.action_dialog.article_ai_quality.run_guidance'),
                                            'qualityLabel' => __('admin.action_dialog.article_ai_quality.run_label'),
                                        ],
                                        'states' => [
                                            'awaiting_quality' => __('admin.articles.ai_optimization.state_awaiting_quality'),
                                            'queued' => __('admin.articles.ai_optimization.state_queued'),
                                            'planning' => __('admin.articles.ai_optimization.state_planning'),
                                            'rewriting' => __('admin.articles.ai_optimization.state_rewriting'),
                                            'validating' => __('admin.articles.ai_optimization.state_validating'),
                                            'evaluating' => __('admin.articles.ai_optimization.state_evaluating'),
                                            'candidate_ready' => __('admin.articles.ai_optimization.state_candidate_ready'),
                                            'applying' => __('admin.articles.ai_optimization.state_applying'),
                                            'completed' => __('admin.articles.ai_optimization.state_completed'),
                                            'needs_review' => __('admin.articles.ai_optimization.state_needs_review'),
                                            'failed' => __('admin.articles.ai_optimization.state_failed'),
                                            'stale' => __('admin.articles.ai_optimization.state_stale'),
                                            'cancelled' => __('admin.articles.ai_optimization.state_cancelled'),
                                        ],
                                    ];
                                @endphp
                                <script type="application/json" data-ai-optimization-initial>@json($aiOptimizationData)</script>
                                <script type="application/json" data-ai-optimization-i18n>@json($aiOptimizationI18n)</script>
                            </div>
                            @if(! $aiQualityEnabled && ! $aiQualityCheck)
                                <div class="px-6 py-6 text-sm text-gray-600">
                                    <p>{{ __('admin.articles.ai_quality.disabled') }}</p>
                                    <p class="mt-2 leading-6 text-gray-500">{{ __('admin.articles.ai_quality.manual_help') }}</p>
                                </div>
                            @elseif(! $aiQualityCheck)
                                <div class="px-6 py-6 text-sm leading-6 text-amber-800">
                                    {{ __('admin.articles.ai_quality.not_started_help') }}
                                </div>
                            @elseif(in_array($aiQualityStatus, ['queued', 'running'], true))
                                <div
                                    data-ai-quality-progress
                                    data-active="true"
                                    data-status-url="{{ \App\Support\AdminWeb::routePath('admin.articles.ai-quality.status', ['articleId' => (int) $articleId]) }}"
                                    data-deadline-at="{{ $aiQualityProgressData['active_deadline_at'] ?? $aiQualityProgressData['deadline_at'] ?? '' }}"
                                    data-deadline-exceeded="{{ __('admin.articles.ai_quality.progress_deadline_exceeded') }}"
                                    data-poll-unavailable="{{ __('admin.articles.ai_quality.progress_poll_unavailable') }}"
                                    data-session-expired="{{ __('admin.articles.ai_quality.progress_session_expired') }}"
                                    data-load-unavailable="{{ __('admin.articles.ai_quality.progress_load_unavailable') }}"
                                    aria-busy="true"
                                    class="px-6 py-5"
                                >
                                    <div class="flex items-start justify-between gap-5">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 ring-4 ring-sky-50">
                                                <i data-lucide="loader-circle" class="h-4 w-4 animate-spin motion-reduce:animate-none"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <p data-ai-quality-progress-message aria-live="polite" class="text-sm font-semibold text-sky-950">
                                                    {{ $aiQualityProgressData['message'] ?? __('admin.articles.ai_quality.progress_queued') }}
                                                </p>
                                                <p data-ai-quality-progress-detail class="mt-1 text-sm leading-6 text-sky-800/80">
                                                    {{ $aiQualityProgressData['detail'] ?? __('admin.articles.ai_quality.progress_queued_detail') }}
                                                </p>
                                            </div>
                                        </div>
                                        <span data-ai-quality-progress-percent class="shrink-0 font-mono text-xl font-semibold tabular-nums text-sky-800">
                                            {{ $aiQualityProgressPercent }}%
                                        </span>
                                    </div>

                                    <div class="mt-4">
                                        <progress
                                            data-ai-quality-progress-bar
                                            role="progressbar"
                                            aria-label="{{ __('admin.articles.ai_quality.pending') }}"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                            aria-valuenow="{{ $aiQualityProgressPercent }}"
                                            value="{{ $aiQualityProgressPercent }}"
                                            max="100"
                                            class="gf-ai-quality-progress"
                                        ></progress>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-sky-800/75">
                                        <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span data-ai-quality-progress-segments>
                                                {{ $aiQualityProgressData['segments_label'] ?? __('admin.articles.ai_quality.progress_preparing') }}
                                            </span>
                                            <span data-ai-quality-progress-elapsed>
                                                {{ $aiQualityProgressData['elapsed_label'] ?? __('admin.articles.ai_quality.progress_elapsed', [
                                                    'seconds' => 0,
                                                    'minutes' => max(1, (int) ceil((
                                                        (int) config('geoflow.ai_quality_deadline_seconds', 180)
                                                        + ($aiQualityIsSampled
                                                            ? (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45)
                                                                + (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10)
                                                            : 0)
                                                    ) / 60)),
                                                ]) }}
                                            </span>
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i>
                                            {{ __('admin.articles.ai_quality.progress_auto_refresh') }}
                                        </span>
                                    </div>

                                    <p data-ai-quality-progress-error role="status" aria-live="polite" class="mt-3 hidden rounded-md bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 ring-1 ring-inset ring-amber-200"></p>
                                </div>
                            @elseif(in_array($aiQualityStatus, ['failed', 'stale', 'cancelled'], true) || $aiQualityDecision === 'error')
                                @php
                                    $aiQualityFailure = is_array($aiQualityProgressData['failure'] ?? null)
                                        ? $aiQualityProgressData['failure']
                                        : [
                                            'code' => $aiQualityProgressData['safe_error_code'] ?? 'inspection_failed',
                                            'title' => __('admin.articles.ai_quality.failure_title_generic'),
                                            'reason' => __('admin.articles.ai_quality.failure_reason_generic'),
                                            'next_step' => __('admin.articles.ai_quality.failure_next_step_retry'),
                                            'retryable' => true,
                                        ];
                                    $aiQualityFailureCode = (string) ($aiQualityFailure['code'] ?? 'inspection_failed');
                                    $aiQualityFailureAction = match (true) {
                                        in_array($aiQualityFailureCode, [
                                            'provider_authentication_failed',
                                            'provider_quota_exhausted',
                                            'model_quota_exceeded',
                                            'model_unavailable',
                                            'structured_output_unsupported',
                                            'invalid_model_output',
                                        ], true) => [
                                            'type' => 'model-settings',
                                            'label' => __('admin.articles.ai_quality.failure_open_model_settings'),
                                            'icon' => 'settings',
                                            'url' => route('admin.ai-models.index'),
                                        ],
                                        $aiQualityFailureCode === 'evidence_retrieval_failed' => [
                                            'type' => 'knowledge-bases',
                                            'label' => __('admin.articles.ai_quality.failure_open_knowledge_bases'),
                                            'icon' => 'library-big',
                                            'url' => route('admin.knowledge-bases.index'),
                                        ],
                                        in_array($aiQualityFailureCode, ['input_too_large', 'input_changed'], true) => [
                                            'type' => 'edit-article',
                                            'label' => __('admin.articles.ai_quality.failure_edit_article'),
                                            'icon' => 'file-pen-line',
                                            'url' => '#article-content-editor',
                                        ],
                                        $aiQualityFailureCode === 'sampling_policy_disabled' && $aiQualityCheck->task_id => [
                                            'type' => 'task-settings',
                                            'label' => __('admin.articles.ai_quality.failure_open_task_settings'),
                                            'icon' => 'list-checks',
                                            'url' => route('admin.tasks.edit', ['taskId' => (int) $aiQualityCheck->task_id]),
                                        ],
                                        (bool) ($aiQualityFailure['retryable'] ?? false) => [
                                            'type' => 'retry',
                                            'label' => __('admin.articles.ai_quality.failure_retry_saved'),
                                            'icon' => 'refresh-cw',
                                            'url' => null,
                                        ],
                                        default => null,
                                    };
                                @endphp
                                <div data-ai-quality-failure class="space-y-4 px-6 py-6">
                                    <div class="rounded-lg border border-red-200 bg-white p-5 shadow-sm">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700 ring-4 ring-red-50">
                                                    <i data-lucide="triangle-alert" class="h-5 w-5"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="text-base font-semibold text-red-950">{{ $aiQualityFailure['title'] }}</p>
                                                    <p class="mt-1 text-sm leading-6 text-red-800">{{ __('admin.articles.ai_quality.failure_no_score') }}</p>
                                                </div>
                                            </div>
                                            <span class="inline-flex w-fit shrink-0 rounded-md bg-red-50 px-2.5 py-1 font-mono text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200">
                                                {{ __('admin.articles.ai_quality.failure_error_code', ['code' => $aiQualityFailure['code']]) }}
                                            </span>
                                        </div>

                                        <dl class="mt-5 grid gap-3 lg:grid-cols-2">
                                            <div class="rounded-md bg-red-50/70 px-4 py-3 ring-1 ring-inset ring-red-100">
                                                <dt class="text-xs font-semibold uppercase tracking-wide text-red-700">{{ __('admin.articles.ai_quality.failure_reason_label') }}</dt>
                                                <dd class="mt-1 text-sm leading-6 text-red-950">{{ $aiQualityFailure['reason'] }}</dd>
                                            </div>
                                            <div class="rounded-md bg-blue-50 px-4 py-3 ring-1 ring-inset ring-blue-100">
                                                <dt class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ __('admin.articles.ai_quality.failure_next_step_label') }}</dt>
                                                <dd class="mt-1 text-sm leading-6 text-blue-950">{{ $aiQualityFailure['next_step'] }}</dd>
                                            </div>
                                        </dl>

                                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                                <span class="rounded-full bg-gray-100 px-2.5 py-1">{{ $aiQualityProgressData['elapsed_label'] ?? '' }}</span>
                                                @if(! empty($aiQualityFailure['provider_http_status']))
                                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 font-mono">HTTP {{ $aiQualityFailure['provider_http_status'] }}</span>
                                                @endif
                                                @if(! empty($aiQualityFailure['provider_code']))
                                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 font-mono">{{ $aiQualityFailure['provider_code'] }}</span>
                                                @endif
                                                @if($aiQualityCheck->knowledge_coverage)
                                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 ring-1 ring-inset ring-emerald-100">
                                                        {{ __('admin.articles.ai_quality.failure_evidence_ready') }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if(is_array($aiQualityFailureAction))
                                                @if($aiQualityFailureAction['type'] === 'retry')
                                                    <button
                                                        type="submit"
                                                        form="article-ai-quality-recheck-form"
                                                        data-ai-quality-failure-action="retry"
                                                        data-admin-confirm-submit
                                                        disabled
                                                        aria-disabled="true"
                                                        class="inline-flex min-h-10 items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 active:translate-y-px"
                                                    >
                                                        <i data-lucide="{{ $aiQualityFailureAction['icon'] }}" class="mr-2 h-4 w-4"></i>
                                                        {{ $aiQualityFailureAction['label'] }}
                                                    </button>
                                                @else
                                                    <a
                                                        href="{{ $aiQualityFailureAction['url'] }}"
                                                        data-ai-quality-failure-action="{{ $aiQualityFailureAction['type'] }}"
                                                        class="inline-flex min-h-10 items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 active:translate-y-px"
                                                    >
                                                        <i data-lucide="{{ $aiQualityFailureAction['icon'] }}" class="mr-2 h-4 w-4"></i>
                                                        {{ $aiQualityFailureAction['label'] }}
                                                    </a>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="space-y-5 p-6">
                                    @if(in_array($aiQualityWorkflowApplyStatus, ['failed', 'exhausted'], true))
                                        <div data-ai-quality-workflow-failure class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-4 text-amber-950">
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="flex items-start gap-3">
                                                    <i data-lucide="workflow" class="mt-0.5 h-5 w-5 shrink-0 text-amber-700"></i>
                                                    <div>
                                                        <p class="text-sm font-semibold">{{ __('admin.articles.ai_quality.workflow_failure_title') }}</p>
                                                        <p class="mt-1 text-sm leading-6 text-amber-800">
                                                            {{ $aiQualityWorkflowApplyStatus === 'exhausted'
                                                                ? __('admin.articles.ai_quality.workflow_failure_exhausted')
                                                                : __('admin.articles.ai_quality.workflow_failure_retrying') }}
                                                        </p>
                                                    </div>
                                                </div>
                                                @if($aiQualityWorkflowApplyStatus === 'exhausted')
                                                    <button type="submit" form="article-ai-quality-workflow-retry-form" data-ai-quality-workflow-retry data-admin-confirm-submit disabled aria-disabled="true" class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-md bg-amber-900 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800 disabled:cursor-not-allowed disabled:opacity-60">
                                                        <i data-lucide="rotate-cw" class="mr-2 h-4 w-4"></i>
                                                        {{ __('admin.articles.ai_quality.workflow_retry_button') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    @if($aiQualityIsSampled)
                                        <div data-ai-quality-sampled-result class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-4 text-sky-950">
                                            <div class="flex items-start gap-3">
                                                <i data-lucide="scan-line" class="mt-0.5 h-5 w-5 shrink-0 text-sky-700"></i>
                                                <div>
                                                    <p class="text-sm font-semibold">{{ __('admin.articles.ai_quality.sampled_notice_title') }}</p>
                                                    <p class="mt-1 text-sm leading-6 text-sky-800">{{ __('admin.articles.ai_quality.sampled_notice_desc') }}</p>
                                                    <div class="mt-3 flex flex-wrap gap-2 text-xs font-medium">
                                                        <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-sky-100">{{ __('admin.articles.ai_quality.coverage_chars', ['checked' => (int) ($aiQualityCoverage['checked_chars'] ?? 0), 'total' => (int) ($aiQualityCoverage['total_chars'] ?? 0)]) }}</span>
                                                        <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-sky-100">{{ __('admin.articles.ai_quality.coverage_claims', ['checked' => (int) ($aiQualityCoverage['mandatory_claims_covered'] ?? 0), 'total' => (int) ($aiQualityCoverage['mandatory_claims_total'] ?? 0)]) }}</span>
                                                        <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-sky-100">{{ __('admin.articles.ai_quality.coverage_ranges', ['count' => (int) ($aiQualityCoverage['range_count'] ?? 0)]) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="grid gap-3 lg:grid-cols-5">
                                        <div class="rounded-lg border border-gray-200 bg-white p-4 lg:col-span-1">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $aiQualityIsSampled ? __('admin.articles.ai_quality.sampled_score_label') : __('admin.articles.ai_quality.score') }}</p>
                                            <p class="mt-2 font-mono text-4xl font-bold {{ (int) ($aiQualityCheck->score ?? 0) >= (int) $aiQualityCheck->pass_score ? 'text-emerald-600' : 'text-red-600' }}">
                                                {{ $aiQualityCheck->score === null ? '-' : (int) $aiQualityCheck->score }}
                                            </p>
                                            <p class="mt-2 text-xs text-gray-500">{{ __('admin.articles.ai_quality.pass_score', ['score' => (int) $aiQualityCheck->pass_score]) }}</p>
                                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.articles.ai_quality.manual_floor', ['score' => (int) $aiQualityCheck->manual_override_min_score]) }}</p>
                                        </div>
                                        <div class="rounded-lg border border-gray-200 bg-white p-4 lg:col-span-4">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.articles.ai_quality.summary') }}</p>
                                                @if($aiQualityCheck->knowledge_coverage)
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                                        {{ __('admin.articles.ai_quality.knowledge_coverage', [
                                                            'coverage' => __('admin.articles.ai_quality.coverage_'.$aiQualityCheck->knowledge_coverage),
                                                        ]) }}
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="mt-2 text-sm leading-6 text-gray-800">{{ $aiQualityCheck->summary ?: $aiQualityCheck->error_message }}</p>
                                            <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                                @foreach($aiQualityDimensionWeights as $dimension => $weight)
                                                    @php
                                                        $dimensionScores = is_array($aiQualityCheck->dimension_scores) ? $aiQualityCheck->dimension_scores : [];
                                                        $dimensionScore = array_key_exists($dimension, $dimensionScores)
                                                            ? (int) $dimensionScores[$dimension]
                                                            : null;
                                                    @endphp
                                                    <div class="rounded-md bg-gray-50 px-3 py-2 ring-1 ring-gray-100">
                                                        <div class="flex items-center justify-between gap-2 text-xs">
                                                            <span class="text-gray-600">{{ __('admin.articles.ai_quality.dimension_'.$dimension) }}</span>
                                                            <span class="font-mono font-semibold text-gray-900">{{ $dimensionScore === null ? '—' : $dimensionScore }}/{{ $weight }}</span>
                                                        </div>
                                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                                            <div class="h-full rounded-full {{ $dimensionScore === null ? 'bg-gray-300' : ($dimensionScore >= $weight * 0.8 ? 'bg-emerald-500' : ($dimensionScore >= $weight * 0.6 ? 'bg-amber-500' : 'bg-red-500')) }}" style="width: {{ $dimensionScore === null ? 0 : min(100, max(0, $weight > 0 ? ($dimensionScore / $weight) * 100 : 0)) }}%"></div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <div class="mb-3 flex items-center justify-between gap-3">
                                            <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.articles.ai_quality.issues') }}</h4>
                                            <span class="text-xs text-gray-500">{{ __('admin.articles.ai_quality.issue_count', ['count' => count($aiQualityIssues)]) }}</span>
                                        </div>
                                        @if(empty($aiQualityIssues))
                                            <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $aiQualityIsSampled ? __('admin.articles.ai_quality.sampled_no_issues') : __('admin.articles.ai_quality.no_issues') }}</p>
                                        @else
                                            <div class="grid gap-3">
                                                @foreach($aiQualityIssues as $issue)
                                                    @php
                                                        $severity = (string) ($issue['severity'] ?? 'low');
                                                        $severityStyle = match ($severity) {
                                                            'critical' => ['card' => 'border-red-300 bg-red-50', 'badge' => 'bg-red-600 text-white', 'quote' => 'border-red-200 bg-red-100/70 text-red-950'],
                                                            'high' => ['card' => 'border-orange-300 bg-orange-50', 'badge' => 'bg-orange-700 text-white', 'quote' => 'border-orange-200 bg-orange-100/70 text-orange-950'],
                                                            'medium' => ['card' => 'border-amber-300 bg-amber-50', 'badge' => 'bg-amber-700 text-white', 'quote' => 'border-amber-200 bg-amber-100/70 text-amber-950'],
                                                            default => ['card' => 'border-blue-200 bg-blue-50', 'badge' => 'bg-blue-600 text-white', 'quote' => 'border-blue-200 bg-blue-100/70 text-blue-950'],
                                                        };
                                                        $refs = array_values(array_filter(array_merge(
                                                            (array) ($issue['knowledge_refs'] ?? []),
                                                            (array) ($issue['legal_refs'] ?? []),
                                                        )));
                                                    @endphp
                                                    <details data-ai-quality-issue class="group overflow-hidden rounded-lg border {{ $severityStyle['card'] }}">
                                                        <summary
                                                            data-ai-quality-issue-summary
                                                            class="flex min-h-14 cursor-pointer list-none items-center gap-2 px-4 py-3 transition-colors duration-150 [@media(hover:hover)]:hover:bg-white/40 active:scale-[.995] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 [&::-webkit-details-marker]:hidden"
                                                        >
                                                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold uppercase {{ $severityStyle['badge'] }}">{{ $severity }}</span>
                                                            <span class="hidden max-w-48 shrink-0 truncate rounded-full bg-white px-2.5 py-1 font-mono text-xs text-gray-700 ring-1 ring-black/5 sm:inline-flex">{{ $issue['code'] ?? '' }}</span>
                                                            <span class="hidden shrink-0 text-xs text-gray-600 lg:inline">
                                                                {{ __('admin.articles.ai_quality.original_location', [
                                                                    'field' => __('admin.security.field_'.($issue['field'] ?? 'content')),
                                                                    'paragraph' => max(1, (int) ($issue['paragraph_index'] ?? 1)),
                                                                ]) }}
                                                            </span>
                                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-800" title="{{ $issue['quote'] ?? '' }}">“{{ $issue['quote'] ?? '' }}”</span>
                                                            <span class="sr-only group-open:hidden">{{ __('admin.articles.ai_quality.issue_expand') }}</span>
                                                            <span class="hidden group-open:inline group-open:sr-only">{{ __('admin.articles.ai_quality.issue_collapse') }}</span>
                                                            <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-gray-500 group-open:rotate-180" aria-hidden="true"></i>
                                                        </summary>

                                                        <div data-ai-quality-issue-details class="border-t border-black/5 px-4 pb-4 pt-3">
                                                            <div class="flex justify-end">
                                                                <button
                                                                    type="button"
                                                                    class="inline-flex min-h-10 items-center rounded-md bg-white px-3 text-xs font-semibold text-gray-700 ring-1 ring-black/10 transition-colors duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                                                    data-ai-quality-locate
                                                                    data-field="{{ $issue['field'] ?? 'content' }}"
                                                                    data-quote="{{ $issue['quote'] ?? '' }}"
                                                                    data-start-offset="{{ $issue['start_offset'] ?? '' }}"
                                                                    data-end-offset="{{ $issue['end_offset'] ?? '' }}"
                                                                >
                                                                    <i data-lucide="locate-fixed" class="mr-1.5 h-3.5 w-3.5"></i>
                                                                    {{ __('admin.articles.ai_quality.locate_action') }}
                                                                </button>
                                                            </div>
                                                            <blockquote class="mt-3 rounded-md border px-3 py-2 text-sm font-medium leading-6 {{ $severityStyle['quote'] }}">“{{ $issue['quote'] ?? '' }}”</blockquote>
                                                            <div class="mt-3 grid gap-3 text-sm lg:grid-cols-2">
                                                                @if(! empty($issue['reason']))
                                                                    <div><span class="font-semibold text-gray-900">{{ __('admin.articles.ai_quality.reason') }}：</span><span class="text-gray-700">{{ $issue['reason'] }}</span></div>
                                                                @endif
                                                                @if(! empty($issue['suggestion']))
                                                                    <div><span class="font-semibold text-gray-900">{{ __('admin.articles.ai_quality.suggestion') }}：</span><span class="text-gray-700">{{ $issue['suggestion'] }}</span></div>
                                                                @endif
                                                                @if(! empty($issue['article_claim']))
                                                                    <div><span class="font-semibold text-gray-900">{{ __('admin.articles.ai_quality.article_claim') }}：</span><span class="text-gray-700">{{ $issue['article_claim'] }}</span></div>
                                                                @endif
                                                                @if(! empty($issue['evidence_value']))
                                                                    <div><span class="font-semibold text-gray-900">{{ __('admin.articles.ai_quality.evidence_value') }}：</span><span class="text-gray-700">{{ $issue['evidence_value'] }}</span></div>
                                                                @endif
                                                            </div>
                                                            @if(! empty($refs))
                                                                <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-gray-600">
                                                                    <span class="font-semibold">{{ __('admin.articles.ai_quality.references') }}：</span>
                                                                    @foreach($refs as $ref)
                                                                        <span class="rounded bg-white px-2 py-0.5 font-mono ring-1 ring-black/5">{{ $ref }}</span>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </details>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    @if(! empty($aiQualityUncertainties))
                                        <div>
                                            <h4 class="mb-3 text-sm font-semibold text-gray-900">{{ __('admin.articles.ai_quality.uncertainties') }}</h4>
                                            <div class="grid gap-2 lg:grid-cols-2">
                                                @foreach($aiQualityUncertainties as $uncertainty)
                                                    <div class="rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm text-violet-950">
                                                        <p class="font-semibold">{{ $uncertainty['claim'] ?? '' }}</p>
                                                        <p class="mt-1 leading-5">{{ $uncertainty['reason'] ?? '' }}</p>
                                                        @if(! empty($uncertainty['needed_evidence']))
                                                            <p class="mt-2 text-xs text-violet-700">{{ $uncertainty['needed_evidence'] }}</p>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if((string) $aiQualityCheck->decision === 'needs_review' && ! $aiQualityCheck->is_overridden)
                                        <div class="rounded-lg border border-blue-200 bg-white p-4">
                                            <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.articles.ai_quality.override_title') }}</h4>
                                            <p class="mt-1 text-xs leading-5 text-gray-500">{{ __('admin.articles.ai_quality.override_help') }}</p>
                                            <textarea form="article-ai-quality-override-form" name="ai_quality_override_reason" rows="3" required minlength="4" maxlength="1000" class="mt-3 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.articles.ai_quality.override_placeholder') }}">{{ old('ai_quality_override_reason') }}</textarea>
                                            <button type="submit" form="article-ai-quality-override-form" data-admin-confirm-submit disabled aria-disabled="true" class="mt-3 inline-flex items-center rounded-md bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                                                <i data-lucide="user-check" class="mr-1.5 h-4 w-4"></i>
                                                {{ __('admin.articles.ai_quality.override_action') }}
                                            </button>
                                        </div>
                                    @elseif($aiQualityCheck->is_overridden)
                                        <p class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                                            {{ __('admin.articles.ai_quality.override_record', [
                                                'name' => $aiQualityCheck->overridden_by_name ?: '#'.$aiQualityCheck->overridden_by,
                                                'time' => $aiQualityCheck->overridden_at?->format('Y-m-d H:i') ?? '',
                                                'reason' => $aiQualityCheck->override_reason,
                                            ]) }}
                                        </p>
                                    @endif

                                    @if(($aiQualityHistory ?? collect())->count() > 1)
                                        <details class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                                            <summary class="cursor-pointer text-sm font-semibold text-gray-800">{{ __('admin.articles.ai_quality.history') }}</summary>
                                            <div class="mt-3 grid gap-3">
                                                @foreach($aiQualityHistory as $historyCheck)
                                                    @continue((int) $historyCheck->id === (int) $aiQualityCheck->id)
                                                    @php
                                                        $historyIssues = is_array($historyCheck->issues) ? $historyCheck->issues : [];
                                                        $historySnapshot = is_array($historyCheck->article_snapshot) ? $historyCheck->article_snapshot : [];
                                                    @endphp
                                                    <details data-ai-quality-history-check="{{ $historyCheck->id }}" class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                                        <summary class="cursor-pointer text-sm font-medium text-gray-800">
                                                            {{ __('admin.articles.ai_quality.history_item', [
                                                                'id' => $historyCheck->id,
                                                                'status' => __('admin.articles.ai_quality.status_'.$historyCheck->status),
                                                                'score' => $historyCheck->score ?? __('admin.articles.ai_quality.unscored_label'),
                                                            ]) }}
                                                            @if($historyCheck->finished_at)
                                                                <span class="ml-2 text-xs font-normal text-gray-500">{{ $historyCheck->finished_at->format('Y-m-d H:i') }}</span>
                                                            @endif
                                                        </summary>
                                                        <div class="mt-3 space-y-3 border-t border-gray-200 pt-3">
                                                            <div class="rounded-md bg-white px-3 py-2 text-sm leading-6 text-gray-700 ring-1 ring-gray-100">
                                                                <span class="font-semibold text-gray-900">{{ __('admin.articles.ai_quality.summary') }}：</span>
                                                                {{ $historyCheck->summary ?: $historyCheck->error_message }}
                                                            </div>
                                                            @foreach($historyIssues as $historyIssue)
                                                                @php
                                                                    $historySeverity = (string) ($historyIssue['severity'] ?? 'low');
                                                                    $historyStyle = match ($historySeverity) {
                                                                        'critical' => 'border-red-200 bg-red-50 text-red-950',
                                                                        'high' => 'border-orange-200 bg-orange-50 text-orange-950',
                                                                        'medium' => 'border-amber-200 bg-amber-50 text-amber-950',
                                                                        default => 'border-blue-200 bg-blue-50 text-blue-950',
                                                                    };
                                                                    $historyField = (string) ($historyIssue['field'] ?? 'content');
                                                                    $historySource = (string) ($historySnapshot[$historyField] ?? '');
                                                                    $historyStart = max(0, (int) ($historyIssue['start_offset'] ?? 0));
                                                                    $historyEnd = max($historyStart, (int) ($historyIssue['end_offset'] ?? $historyStart));
                                                                    $historyContextStart = max(0, $historyStart - 60);
                                                                    $historyContextLength = max(160, ($historyEnd - $historyContextStart) + 60);
                                                                    $historyContext = mb_substr($historySource, $historyContextStart, $historyContextLength, 'UTF-8');
                                                                    $historyRefs = array_values(array_filter(array_merge(
                                                                        (array) ($historyIssue['knowledge_refs'] ?? []),
                                                                        (array) ($historyIssue['legal_refs'] ?? []),
                                                                    )));
                                                                @endphp
                                                                <details data-ai-quality-history-issue class="group overflow-hidden rounded-lg border {{ $historyStyle }}">
                                                                    <summary
                                                                        data-ai-quality-issue-summary
                                                                        class="flex min-h-12 cursor-pointer list-none items-center gap-2 px-3 py-2.5 transition-colors duration-150 [@media(hover:hover)]:hover:bg-white/40 active:scale-[.995] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 [&::-webkit-details-marker]:hidden"
                                                                    >
                                                                        <span class="shrink-0 rounded-full bg-white/80 px-2 py-0.5 text-xs font-bold uppercase ring-1 ring-black/5">{{ $historySeverity }}</span>
                                                                        <span class="hidden max-w-48 shrink-0 truncate font-mono text-xs sm:inline">{{ $historyIssue['code'] ?? '' }}</span>
                                                                        <span class="hidden shrink-0 text-xs lg:inline">{{ __('admin.articles.ai_quality.original_location', [
                                                                            'field' => __('admin.security.field_'.$historyField),
                                                                            'paragraph' => max(1, (int) ($historyIssue['paragraph_index'] ?? 1)),
                                                                        ]) }}</span>
                                                                        <span class="min-w-0 flex-1 truncate text-sm font-medium" title="{{ $historyIssue['quote'] ?? '' }}">“{{ $historyIssue['quote'] ?? '' }}”</span>
                                                                        <span class="sr-only group-open:hidden">{{ __('admin.articles.ai_quality.issue_expand') }}</span>
                                                                        <span class="hidden group-open:inline group-open:sr-only">{{ __('admin.articles.ai_quality.issue_collapse') }}</span>
                                                                        <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 opacity-60 group-open:rotate-180" aria-hidden="true"></i>
                                                                    </summary>
                                                                    <div data-ai-quality-issue-details class="border-t border-black/5 px-3 pb-3 pt-2.5">
                                                                        <blockquote class="rounded-md bg-white/70 px-3 py-2 text-sm font-medium leading-6 ring-1 ring-black/5">“{{ $historyIssue['quote'] ?? '' }}”</blockquote>
                                                                        @if($historyContext !== '')
                                                                            <div class="mt-2 rounded-md bg-white/70 px-3 py-2 text-xs leading-5 text-gray-700 ring-1 ring-black/5">
                                                                                <span class="font-semibold text-gray-900">{{ __('admin.articles.ai_quality.history_snapshot') }}：</span>
                                                                                {{ $historyContext }}
                                                                            </div>
                                                                        @endif
                                                                        <div class="mt-2 grid gap-2 text-sm lg:grid-cols-2">
                                                                            @if(! empty($historyIssue['reason']))
                                                                                <p><span class="font-semibold">{{ __('admin.articles.ai_quality.reason') }}：</span>{{ $historyIssue['reason'] }}</p>
                                                                            @endif
                                                                            @if(! empty($historyIssue['suggestion']))
                                                                                <p><span class="font-semibold">{{ __('admin.articles.ai_quality.suggestion') }}：</span>{{ $historyIssue['suggestion'] }}</p>
                                                                            @endif
                                                                        </div>
                                                                        @if(! empty($historyRefs))
                                                                            <p class="mt-2 text-xs"><span class="font-semibold">{{ __('admin.articles.ai_quality.references') }}：</span>{{ implode(' · ', $historyRefs) }}</p>
                                                                        @endif
                                                                    </div>
                                                                </details>
                                                            @endforeach
                                                        </div>
                                                    </details>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @endif
                            </div>
                        </section>
                    @endif

                    <div id="article-content-editor" class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.basic_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <div class="flex items-center justify-between gap-3">
                                    <label for="title" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.title') }} *</label>
                                    @if(! $isEdit)
                                        <button
                                            type="button"
                                            id="article-title-picker-open"
                                            class="inline-flex shrink-0 items-center rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:border-blue-300 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                        >
                                            <i data-lucide="library-big" class="mr-1.5 h-4 w-4"></i>
                                            {{ __('admin.article_assistant.title_picker.open') }}
                                        </button>
                                    @endif
                                </div>
                                <input id="title" type="text" name="title" maxlength="500" required value="{{ $formData['title'] }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.title') }}">
                                @if(! $isEdit)
                                    <input id="article-source-title-id" type="hidden" name="source_title_id" value="{{ $formData['source_title_id'] }}">
                                    <input id="article-is-ai-generated" type="hidden" name="is_ai_generated" value="{{ $formData['is_ai_generated'] }}">
                                    <div id="article-selected-title" class="mt-2 hidden items-start justify-between gap-3 rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-xs font-semibold text-blue-700">{{ __('admin.article_assistant.title_picker.selected_label') }}</span>
                                                <span id="article-selected-title-library" class="rounded-full bg-white px-2 py-0.5 text-xs text-blue-700 ring-1 ring-blue-100"></span>
                                            </div>
                                            <p id="article-selected-title-meta" class="mt-1 truncate text-xs text-gray-600"></p>
                                        </div>
                                        <button type="button" id="article-selected-title-clear" class="shrink-0 rounded-md p-1 text-gray-400 hover:bg-white hover:text-gray-700" aria-label="{{ __('admin.article_assistant.title_picker.clear') }}">
                                            <i data-lucide="x" class="h-4 w-4"></i>
                                        </button>
                                    </div>
                                @endif
                            </div>
                            <div>
                                <label for="excerpt" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.excerpt') }}</label>
                                <textarea id="excerpt" name="excerpt" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.excerpt') }}">{{ $formData['excerpt'] }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.content_title') }}</h3>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">{{ __($i18nRoot.'.help.markdown_supported') }}</span>
                                    <button
                                        type="button"
                                        id="article-editor-copy-markdown"
                                        class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                    >
                                        <i data-lucide="copy" class="mr-1.5 h-4 w-4"></i>
                                        {{ __('admin.article_editor.copy.button') }}
                                    </button>
                                    <button
                                        type="button"
                                        id="article-editor-copy-wechat-html"
                                        class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm hover:border-emerald-300 hover:bg-emerald-100 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <i data-lucide="copy-check" class="mr-1.5 h-4 w-4"></i>
                                        {{ __('admin.article_editor.wechat.button') }}
                                    </button>
                                </div>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">{{ __('admin.article_editor.editor_desc') }}</p>
                        </div>
                        <div class="px-6 py-4">
                            <textarea id="content-textarea" name="content" class="hidden">{{ $formData['content'] }}</textarea>
                            @if(! $isEdit)
                                <section
                                    id="article-create-assistant"
                                    class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-gray-50/80 shadow-sm"
                                    data-titles-url="{{ \App\Support\AdminWeb::routePath('admin.articles.editor.titles') }}"
                                    data-generate-url="{{ \App\Support\AdminWeb::routePath('admin.articles.editor.generate') }}"
                                >
                                    <div class="flex items-start gap-3 px-4 py-4">
                                        <div class="flex min-w-0 flex-1 items-start gap-3">
                                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                                                <i data-lucide="sparkles" class="h-5 w-5"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.article_assistant.generate.title') }}</h4>
                                                <p class="mt-1 text-xs leading-5 text-gray-600">{{ __('admin.article_assistant.generate.desc') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="grid gap-3 border-t border-gray-200 bg-white/70 px-4 py-4 md:grid-cols-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                                        <div class="min-w-0">
                                            <label for="article-ai-knowledge-base" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.generate.knowledge_label') }}</label>
                                            <div class="relative mt-1">
                                                <select id="article-ai-knowledge-base" class="block w-full appearance-none truncate rounded-md border-gray-300 bg-white py-2 pl-3 pr-10 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="">{{ __('admin.article_assistant.generate.knowledge_placeholder') }}</option>
                                                    @foreach(($formOptions['knowledge_bases'] ?? []) as $knowledgeBaseOption)
                                                        <option value="{{ $knowledgeBaseOption['id'] }}">{{ $knowledgeBaseOption['name'] }}</option>
                                                    @endforeach
                                                </select>
                                                <i data-lucide="chevron-down" aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <label for="article-ai-prompt" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.generate.prompt_label') }}</label>
                                            <div class="relative mt-1">
                                                <select id="article-ai-prompt" class="block w-full appearance-none truncate rounded-md border-gray-300 bg-white py-2 pl-3 pr-10 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="">{{ __('admin.article_assistant.generate.prompt_placeholder') }}</option>
                                                    @foreach(($formOptions['content_prompts'] ?? []) as $promptOption)
                                                        <option value="{{ $promptOption['id'] }}">{{ $promptOption['name'] }}</option>
                                                    @endforeach
                                                </select>
                                                <i data-lucide="chevron-down" aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <label for="article-ai-model" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.generate.model_label') }}</label>
                                            <div class="relative mt-1">
                                                <select id="article-ai-model" class="block w-full appearance-none truncate rounded-md border-gray-300 bg-white py-2 pl-3 pr-10 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="">{{ __('admin.article_assistant.generate.model_placeholder') }}</option>
                                                    @foreach(($formOptions['ai_models'] ?? []) as $modelOption)
                                                        <option value="{{ $modelOption['id'] }}">{{ $modelOption['name'] }}@if($modelOption['model_id'] !== '') · {{ $modelOption['model_id'] }}@endif</option>
                                                    @endforeach
                                                </select>
                                                <i data-lucide="chevron-down" aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <div class="flex items-end md:col-span-3 xl:col-span-1">
                                            <button
                                                type="button"
                                                id="article-ai-generate"
                                                class="inline-flex w-full items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 xl:w-auto"
                                            >
                                                <i data-lucide="wand-sparkles" class="mr-2 h-4 w-4"></i>
                                                <span>{{ __('admin.article_assistant.generate.button') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div id="article-ai-status-row" class="hidden border-t border-gray-200 bg-white/70 px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <span id="article-ai-status-icon" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700">
                                                <i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-3">
                                                    <p id="article-ai-status" class="truncate text-xs font-semibold text-gray-700"></p>
                                                    <span id="article-ai-character-count" class="shrink-0 text-xs text-gray-500"></span>
                                                </div>
                                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-blue-100">
                                                    <div id="article-ai-progress" class="h-full w-1/3 animate-pulse rounded-full bg-blue-500"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            @endif
                            <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                <span class="mr-1 text-xs font-medium text-gray-500">{{ __('admin.article_editor.quick_actions.title') }}</span>
                                @foreach($editorQuickActions as $quickAction)
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-700 shadow-sm hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                        data-editor-action="{{ $quickAction['key'] }}"
                                    >
                                        <i data-lucide="{{ $quickAction['icon'] }}" class="mr-1.5 h-4 w-4"></i>
                                        {{ $quickAction['label'] }}
                                    </button>
                                @endforeach
                                <span class="ml-auto text-xs text-gray-500">{{ __('admin.article_editor.message.context_tip') }}</span>
                            </div>
                            <div
                                id="content-editor"
                                class="article-markdown-editor"
                                data-upload-url="{{ $articleImageUploadUrl }}"
                                data-upload-enabled="{{ $isEdit ? '1' : '0' }}"
                                data-wechat-html-url="{{ $articleWechatHtmlUrl }}"
                            ></div>
                            <input id="article-editor-quick-image-input" type="file" accept="image/*" class="hidden">
                            <div id="article-editor-context-menu" class="article-editor-context-menu" hidden>
                                <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.article_editor.quick_actions.context_title') }}</div>
                                @foreach($editorQuickActions as $quickAction)
                                    <button type="button" data-editor-action="{{ $quickAction['key'] }}">
                                        <i data-lucide="{{ $quickAction['icon'] }}" class="h-4 w-4"></i>
                                        <span>{{ $quickAction['label'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                            <div class="mt-3 grid gap-2 text-xs text-gray-500 sm:grid-cols-3">
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.markdown') }}</div>
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.image') }}</div>
                                <div class="rounded-md bg-gray-50 px-3 py-2">{{ __('admin.article_editor.help.crop') }}</div>
                            </div>
                        </div>
                    </div>

                    <section class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <div class="flex items-start gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                                    <i data-lucide="list-checks" class="h-5 w-5"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.articles.quality_scorecard.title') }}</h3>
                                        <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 ring-1 ring-gray-200">{{ __('admin.articles.quality_scorecard.manual_label') }}</span>
                                    </div>
                                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('admin.articles.quality_scorecard.desc') }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="border-b border-gray-100 bg-gray-50 px-6 py-4">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.articles.quality_scorecard.dynamic_title') }}</h4>
                                <span class="text-xs font-medium text-gray-500">{{ __('admin.articles.quality_scorecard.dynamic_desc') }}</span>
                            </div>
                            <div class="grid grid-cols-1 gap-2 md:grid-cols-5">
                                @foreach ($qualityFieldChecks as $fieldCheck)
                                    @php($fieldStatusClass = $fieldCheck['passed'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700')
                                    @php($fieldStatusIcon = $fieldCheck['passed'] ? 'check' : 'circle-alert')
                                    <div class="rounded-lg border px-3 py-2 {{ $fieldStatusClass }}">
                                        <div class="flex items-center gap-1.5 text-xs font-semibold">
                                            <i data-lucide="{{ $fieldStatusIcon }}" class="h-3.5 w-3.5"></i>
                                            {{ $fieldCheck['label'] }}
                                        </div>
                                        <div class="mt-1 text-xs opacity-90">{{ $fieldCheck['passed'] ? $fieldCheck['passText'] : $fieldCheck['pendingText'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-3 px-6 py-4 md:grid-cols-2 xl:grid-cols-5">
                            @foreach ($qualityScorecard as $scorecardItem)
                                @php($scoreStatusClass = $scorecardItem['status_class'] ?? ($scorecardItem['passed'] ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-amber-50 text-amber-700 ring-amber-100'))
                                @php($scoreStatusIcon = $scorecardItem['status_icon'] ?? ($scorecardItem['passed'] ? 'check' : 'circle-alert'))
                                <div class="rounded-lg border border-gray-100 bg-gray-50/80 p-3">
                                    <div class="flex h-full flex-col gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1 {{ $scorecardItem['class'] }}">
                                            <i data-lucide="{{ $scorecardItem['icon'] }}" class="h-4 w-4"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="text-sm font-semibold text-gray-900">{{ $scorecardItem['title'] }}</h4>
                                            <p class="mt-1 text-xs leading-5 text-gray-500">{{ $scorecardItem['desc'] }}</p>
                                        </div>
                                        <span class="mt-auto inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $scoreStatusClass }}">
                                            <i data-lucide="{{ $scoreStatusIcon }}" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ $scorecardItem['status_label'] ?? ($scorecardItem['passed'] ? __('admin.articles.quality_scorecard.ready_label') : __('admin.articles.quality_scorecard.pending_label')) }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if($isEdit)
                            <div class="border-t border-gray-100 px-6 py-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-sm font-semibold text-gray-900">{{ __('admin.articles.quality_scorecard.risk_details_title') }}</h4>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $riskStatusPresentation['class'] }}">
                                                <i data-lucide="{{ $riskStatusPresentation['icon'] }}" class="mr-1.5 h-3.5 w-3.5"></i>
                                                {{ $riskStatusPresentation['label'] }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ __('admin.articles.quality_scorecard.risk_match_summary', ['count' => (int) ($riskScan['match_count'] ?? 0)]) }}
                                            @if(! empty($riskScan['scanned_at'])) · {{ $riskScan['scanned_at'] }} @endif
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.site-settings.sensitive-words') }}" class="inline-flex items-center rounded-md border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                            <i data-lucide="settings-2" class="mr-1.5 h-4 w-4"></i>
                                            {{ __('admin.articles.quality_scorecard.manage_rules') }}
                                        </a>
                                        <button type="submit" form="article-risk-recheck-form" class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-800">
                                            <i data-lucide="refresh-cw" class="mr-1.5 h-4 w-4"></i>
                                            {{ __('admin.articles.quality_scorecard.risk_recheck') }}
                                        </button>
                                    </div>
                                </div>

                                @if($riskDisplayStatus === 'stale')
                                    <p class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">{{ __('admin.articles.quality_scorecard.risk_stale_help') }}</p>
                                @elseif(empty($riskScan))
                                    <p class="mt-4 rounded-lg border border-dashed border-gray-300 px-3 py-4 text-center text-xs text-gray-500">{{ __('admin.articles.quality_scorecard.risk_unscanned_help') }}</p>
                                @elseif(empty($riskScan['matches']))
                                    <p class="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{{ __('admin.articles.quality_scorecard.risk_clean_help') }}</p>
                                @else
                                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                        @foreach($riskScan['matches'] as $match)
                                            <div class="rounded-lg border {{ ($match['severity'] ?? '') === 'blocked' ? 'border-red-200 bg-red-50/60' : 'border-amber-200 bg-amber-50/60' }} p-3">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-semibold text-gray-900">{{ $match['word'] ?? '' }}</span>
                                                    <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-600 ring-1 ring-gray-200">{{ __('admin.security.field_'.($match['field'] ?? 'content')) }}</span>
                                                    <span class="text-xs text-gray-500">× {{ (int) ($match['count'] ?? 0) }}</span>
                                                </div>
                                                <p class="mt-2 break-words text-xs leading-5 text-gray-700">{{ $match['snippet'] ?? '' }}</p>
                                                @if(! empty($match['suggestion']))
                                                    <p class="mt-2 text-xs font-medium text-blue-700">{{ __('admin.articles.quality_scorecard.risk_suggestion') }}：{{ $match['suggestion'] }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if(! empty($riskScan['is_overridden']))
                                    <p class="mt-4 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-700">{{ __('admin.articles.quality_scorecard.risk_overridden') }}：{{ $riskScan['override_reason'] }}</p>
                                @endif
                            </div>
                        @endif
                    </section>

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.seo_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <label for="keywords" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.keywords') }}</label>
                                <input id="keywords" type="text" name="keywords" value="{{ $formData['keywords'] }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.keywords') }}">
                            </div>
                            <div>
                                <label for="meta_description" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.meta_description') }}</label>
                                <textarea id="meta_description" name="meta_description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __($i18nRoot.'.placeholder.meta_description') }}">{{ $formData['meta_description'] }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.publish_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.publish_status') }}</label>
                                <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="draft" @selected($formData['status'] === 'draft')>{{ __('admin.articles.status.draft') }}</option>
                                    <option value="published" @selected($formData['status'] === 'published')>{{ __('admin.articles.status.published') }}</option>
                                    <option value="private" @selected($formData['status'] === 'private')>{{ __('admin.articles.status.private') }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="review_status" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.review_status') }}</label>
                                <select id="review_status" name="review_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="pending" @selected($formData['review_status'] === 'pending')>{{ __('admin.articles.review.pending') }}</option>
                                    <option value="approved" @selected($formData['review_status'] === 'approved')>{{ __('admin.articles.review.approved') }}</option>
                                    <option value="rejected" @selected($formData['review_status'] === 'rejected')>{{ __('admin.articles.review.rejected') }}</option>
                                    <option value="auto_approved" @selected($formData['review_status'] === 'auto_approved')>{{ __('admin.articles.review.auto_approved') }}</option>
                                </select>
                                <p class="mt-2 text-xs text-gray-500">{{ __($i18nRoot.'.help.review_status') }}</p>
                            </div>
                            <div>
                                <label for="risk_override_reason" class="block text-sm font-medium text-gray-700">{{ __('admin.articles.quality_scorecard.risk_override_reason') }}</label>
                                <textarea id="risk_override_reason" name="risk_override_reason" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.articles.quality_scorecard.risk_override_placeholder') }}">{{ old('risk_override_reason') }}</textarea>
                                <p class="mt-2 text-xs text-gray-500">{{ __('admin.articles.quality_scorecard.risk_override_help') }}</p>
                            </div>
                            <div class="rounded-lg border border-blue-100 bg-blue-50/70 p-4">
                                <div class="text-sm font-medium text-gray-900">{{ __($i18nRoot.'.section.recommendation_title') }}</div>
                                <p class="mt-1 text-xs text-gray-600">{{ __($i18nRoot.'.help.recommendation') }}</p>
                                <div class="mt-3 space-y-3">
                                    <label class="flex items-start gap-3 text-sm text-gray-700">
                                        <input type="checkbox" name="is_hot" value="1" @checked((string) $formData['is_hot'] === '1') class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                        <span>
                                            <span class="font-medium text-gray-900">{{ __($i18nRoot.'.field.is_hot') }}</span>
                                            <span class="block text-xs text-gray-500">{{ __($i18nRoot.'.help.is_hot') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 text-sm text-gray-700">
                                        <input type="checkbox" name="is_featured" value="1" @checked((string) $formData['is_featured'] === '1') class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                        <span>
                                            <span class="font-medium text-gray-900">{{ __($i18nRoot.'.field.is_featured') }}</span>
                                            <span class="block text-xs text-gray-500">{{ __($i18nRoot.'.help.is_featured') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">{{ __($i18nRoot.'.section.category_author_title') }}</h3>
                        </div>
                        <div class="px-6 py-4 space-y-4">
                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.category') }} *</label>
                                <select id="category_id" name="category_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="">{{ __($i18nRoot.'.option.select_category') }}</option>
                                    @foreach(($formOptions['categories'] ?? []) as $category)
                                        <option value="{{ (int) $category['id'] }}" @selected($formData['category_id'] === (string) $category['id'])>{{ $category['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="author_id" class="block text-sm font-medium text-gray-700">{{ __($i18nRoot.'.field.author') }} *</label>
                                <select id="author_id" name="author_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="">{{ __($i18nRoot.'.option.select_author') }}</option>
                                    @foreach(($formOptions['authors'] ?? []) as $author)
                                        <option value="{{ (int) $author['id'] }}" @selected($formData['author_id'] === (string) $author['id'])>{{ $author['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    @if($isEdit)
                        <div class="bg-white shadow rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.article_edit.section.info_title') }}</h3>
                            </div>
                            <div class="px-6 py-4 text-sm text-gray-600 space-y-2">
                                <div>{{ __('admin.article_edit.info.article_id') }}: #{{ (int) $articleId }}</div>
                                <div>{{ __('admin.article_edit.info.slug') }}: {{ $formData['slug'] }}</div>
                                <div>{{ __('admin.article_edit.info.source_task') }}: {{ $formData['task_name'] !== '' ? $formData['task_name'] : __('admin.article_edit.info.manual_source') }}</div>
                                <div>{{ __('admin.article_edit.info.published_at') }}: {{ $formData['published_at'] !== '' ? $formData['published_at'] : '-' }}</div>
                            </div>
                        </div>
                        @if($canCreateManualPublication && in_array((string) $formData['review_status'], ['approved', 'auto_approved'], true))
                            <a href="{{ route('admin.manual-publications.create', ['article_id' => (int) $articleId]) }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-purple-600 px-4 py-3 text-sm font-semibold text-white hover:bg-purple-700">
                                <i data-lucide="send" class="h-4 w-4"></i>
                                {{ __('admin.manual_publications.article_action') }}
                            </a>
                        @endif
                    @endif

                    <div class="flex items-center justify-end space-x-3">
                        <a href="{{ route('admin.articles.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            {{ $isEdit ? __('admin.article_edit.button.save_changes') : __('admin.button.create_article') }}
                        </button>
                    </div>
                </div>
            </div>
        </form>
        @if(! $isEdit)
            <div id="article-title-picker-modal" class="fixed inset-0 z-[80] hidden items-center justify-center p-4 sm:p-6" aria-hidden="true">
                <div class="absolute inset-0 bg-[rgba(15,23,42,0.48)]" data-title-picker-close></div>
                <div class="relative flex max-h-[min(780px,calc(100dvh-2rem))] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-[0_24px_72px_rgba(15,23,42,0.28)]" role="dialog" aria-modal="true" aria-labelledby="article-title-picker-title">
                    <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 sm:px-6">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                    <i data-lucide="library-big" class="h-5 w-5"></i>
                                </span>
                                <h3 id="article-title-picker-title" class="text-lg font-semibold text-gray-900">{{ __('admin.article_assistant.title_picker.title') }}</h3>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">{{ __('admin.article_assistant.title_picker.desc') }}</p>
                        </div>
                        <button type="button" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700" data-title-picker-close aria-label="{{ __('admin.button.cancel') }}">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                    <div class="grid gap-3 border-b border-gray-100 bg-gray-50/80 px-5 py-4 sm:grid-cols-[1fr_1fr_1.2fr] sm:px-6">
                        <div>
                            <label for="article-title-library-filter" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.title_picker.library_label') }}</label>
                            <select id="article-title-library-filter" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('admin.article_assistant.title_picker.all_libraries') }}</option>
                                @foreach(($formOptions['title_libraries'] ?? []) as $libraryOption)
                                    <option value="{{ $libraryOption['id'] }}">{{ $libraryOption['name'] }} · {{ $libraryOption['count'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="article-title-usage-filter" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.title_picker.usage_label') }}</label>
                            <select id="article-title-usage-filter" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="unused">{{ __('admin.article_assistant.title_picker.unused') }}</option>
                                <option value="all">{{ __('admin.article_assistant.title_picker.all_titles') }}</option>
                                <option value="used">{{ __('admin.article_assistant.title_picker.used') }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="article-title-search" class="block text-xs font-semibold text-gray-700">{{ __('admin.article_assistant.title_picker.search_label') }}</label>
                            <div class="relative mt-1">
                                <i data-lucide="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-400"></i>
                                <input id="article-title-search" type="search" maxlength="200" class="block w-full rounded-md border-gray-300 bg-white pl-9 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.article_assistant.title_picker.search_placeholder') }}">
                            </div>
                        </div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6">
                        <div id="article-title-picker-loading" class="hidden items-center justify-center py-16 text-sm text-gray-500">
                            <i data-lucide="loader-2" class="mr-2 h-5 w-5 animate-spin text-blue-600"></i>
                            {{ __('admin.article_assistant.title_picker.loading') }}
                        </div>
                        <div id="article-title-picker-empty" class="hidden py-16 text-center">
                            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                <i data-lucide="search-x" class="h-6 w-6"></i>
                            </span>
                            <p class="mt-3 text-sm font-semibold text-gray-700">{{ __('admin.article_assistant.title_picker.empty') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_assistant.title_picker.empty_help') }}</p>
                        </div>
                        <div id="article-title-picker-error" class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>
                        <div id="article-title-picker-results" class="space-y-2"></div>
                    </div>
                    <div class="border-t border-gray-200 bg-white px-5 py-4 sm:px-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p id="article-title-picker-summary" class="text-xs text-gray-500"></p>
                                <p id="article-title-picker-selection" class="mt-1 max-w-2xl truncate text-sm font-semibold text-gray-900">{{ __('admin.article_assistant.title_picker.no_selection') }}</p>
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" id="article-title-picker-prev" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40" aria-label="{{ __('admin.article_assistant.title_picker.previous') }}">
                                    <i data-lucide="chevron-left" class="h-4 w-4"></i>
                                </button>
                                <button type="button" id="article-title-picker-next" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40" aria-label="{{ __('admin.article_assistant.title_picker.next') }}">
                                    <i data-lucide="chevron-right" class="h-4 w-4"></i>
                                </button>
                                <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" data-title-picker-close>{{ __('admin.button.cancel') }}</button>
                                <button type="button" id="article-title-picker-apply" disabled class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                    <i data-lucide="check" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.article_assistant.title_picker.apply') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script type="application/json" id="article-assistant-messages">{!! $articleAssistantMessagesJson !!}</script>
        @endif
        @if($isEdit)
            <form id="article-risk-recheck-form" method="POST" action="{{ route('admin.articles.risk-scan', ['articleId' => (int) $articleId]) }}" class="hidden">
                @csrf
            </form>
            <form id="article-ai-quality-recheck-form" method="POST" action="{{ route('admin.articles.ai-quality.recheck', ['articleId' => (int) $articleId]) }}" class="hidden" data-admin-confirm-form data-admin-confirm-tone="success" data-admin-confirm-title="{{ __('admin.action_dialog.article_ai_quality.run_title') }}" data-admin-confirm-message="{{ __('admin.action_dialog.article_ai_quality.run_message') }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.article_ai_quality.run_guidance') }}" data-admin-confirm-label="{{ __('admin.action_dialog.article_ai_quality.run_label') }}">
                @csrf
            </form>
            <form id="article-ai-quality-workflow-retry-form" method="POST" action="{{ route('admin.articles.ai-quality.workflow-retry', ['articleId' => (int) $articleId]) }}" class="hidden" data-admin-confirm-form data-admin-confirm-tone="success" data-admin-confirm-title="{{ __('admin.action_dialog.article_ai_quality.run_title') }}" data-admin-confirm-message="{{ __('admin.action_dialog.article_ai_quality.run_message') }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.article_ai_quality.run_guidance') }}" data-admin-confirm-label="{{ __('admin.action_dialog.article_ai_quality.run_label') }}">
                @csrf
            </form>
            <form id="article-ai-quality-override-form" method="POST" action="{{ route('admin.articles.ai-quality.override', ['articleId' => (int) $articleId]) }}" class="hidden" data-admin-confirm-form data-admin-confirm-tone="warning" data-admin-confirm-title="{{ __('admin.action_dialog.article_ai_quality.override_title') }}" data-admin-confirm-message="{{ __('admin.action_dialog.article_ai_quality.override_message') }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.article_ai_quality.override_guidance') }}" data-admin-confirm-label="{{ __('admin.action_dialog.article_ai_quality.override_label') }}">
                @csrf
            </form>
        @endif
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/vditor/dist/index.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/cropperjs/cropper.min.css') }}">
    <style>
        .article-markdown-editor .vditor {
            border-color: #d1d5db;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .article-markdown-editor .vditor-toolbar {
            border-bottom-color: #e5e7eb;
            background: #f9fafb;
        }
        .article-markdown-editor .vditor-reset,
        .article-markdown-editor .vditor-ir pre.vditor-reset,
        .article-markdown-editor .vditor-sv .vditor-reset {
            font-size: 15px;
            line-height: 1.8;
        }
        .article-image-modal[aria-hidden="true"] {
            display: none;
        }
        .article-image-modal__backdrop {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.48);
            padding: 24px;
        }
        .article-image-modal__panel {
            width: min(920px, 100%);
            max-height: min(760px, calc(100dvh - 48px));
            overflow: hidden;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 24px 72px rgba(15, 23, 42, 0.28);
        }
        @media (max-width: 480px) {
            .article-image-modal__backdrop { padding: 16px; }
            .article-image-modal__panel { max-height: calc(100dvh - 32px); }
        }
        .article-image-crop-stage {
            display: flex;
            min-height: 320px;
            max-height: 430px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #f8fafc;
            overflow: hidden;
        }
        .article-image-crop-stage img {
            display: block;
            max-width: 100%;
            max-height: 430px;
        }
        .article-image-status[data-tone="error"] {
            color: #b91c1c;
        }
        .article-image-status[data-tone="success"] {
            color: #047857;
        }
        .article-editor-context-menu {
            position: fixed;
            z-index: 70;
            width: 220px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.18);
        }
        .article-editor-context-menu[hidden] {
            display: none;
        }
        .article-editor-context-menu button {
            display: flex;
            width: 100%;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            color: #374151;
            font-size: 14px;
            text-align: left;
        }
        .article-editor-context-menu button:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .ai-quality-located {
            outline: 3px solid rgba(245, 158, 11, 0.65);
            outline-offset: 3px;
            background-color: rgba(254, 243, 199, 0.85) !important;
            transition: background-color 180ms ease, outline-color 180ms ease;
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('vendor/vditor/dist/index.min.js') }}"></script>
    <script src="{{ asset('vendor/cropperjs/cropper.min.js') }}"></script>
    <div id="article-image-modal" class="article-image-modal" aria-hidden="true">
        <div class="article-image-modal__backdrop" data-image-modal-close>
            <div class="article-image-modal__panel" role="dialog" aria-modal="true" aria-labelledby="article-image-modal-title" data-image-modal-panel>
                <div class="border-b border-gray-200 px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 id="article-image-modal-title" class="text-lg font-semibold text-gray-900">{{ __('admin.article_editor.image_modal.title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.article_editor.image_modal.desc') }}</p>
                        </div>
                        <button type="button" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" data-image-modal-close aria-label="{{ __('admin.button.cancel') }}">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                </div>
                <div class="grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <div class="article-image-crop-stage">
                        <img id="article-image-crop-target" alt="">
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label for="article-image-alt" class="block text-sm font-medium text-gray-700">{{ __('admin.article_editor.image_modal.alt_label') }}</label>
                            <input id="article-image-alt" type="text" maxlength="120" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.article_editor.image_modal.alt_placeholder') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.article_editor.image_modal.alt_help') }}</p>
                        </div>
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                            <input id="article-image-crop-enabled" type="checkbox" checked class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block font-medium text-gray-900">{{ __('admin.article_editor.image_modal.crop_label') }}</span>
                                <span class="mt-1 block text-xs text-gray-500">{{ __('admin.article_editor.image_modal.crop_help') }}</span>
                            </span>
                        </label>
                        <div id="article-image-status" class="article-image-status min-h-[1.25rem] text-sm" data-tone=""></div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4">
                    <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" data-image-modal-close>
                        {{ __('admin.button.cancel') }}
                    </button>
                    <button type="button" id="article-image-upload-original" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="image" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.article_editor.image_modal.upload_original') }}
                    </button>
                    <button type="button" id="article-image-upload-cropped" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="crop" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.article_editor.image_modal.upload_cropped') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const textarea = document.getElementById('content-textarea');
            const editorNode = document.getElementById('content-editor');
            const form = textarea ? textarea.closest('form') : null;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const uploadUrl = editorNode?.dataset.uploadUrl || '';
            const uploadEnabled = editorNode?.dataset.uploadEnabled === '1' && uploadUrl !== '';
            const wechatHtmlUrl = editorNode?.dataset.wechatHtmlUrl || '';
            const cropperScriptUrl = @json(asset('vendor/cropperjs/cropper.min.js'));
            const modal = document.getElementById('article-image-modal');
            const cropTarget = document.getElementById('article-image-crop-target');
            const altInput = document.getElementById('article-image-alt');
            const cropEnabledInput = document.getElementById('article-image-crop-enabled');
            const statusNode = document.getElementById('article-image-status');
            const copyMarkdownButton = document.getElementById('article-editor-copy-markdown');
            const copyWechatHtmlButton = document.getElementById('article-editor-copy-wechat-html');
            const uploadOriginalButton = document.getElementById('article-image-upload-original');
            const uploadCroppedButton = document.getElementById('article-image-upload-cropped');
            const quickImageInput = document.getElementById('article-editor-quick-image-input');
            const contextMenu = document.getElementById('article-editor-context-menu');
            let editor = null;
            let currentFile = null;
            let currentObjectUrl = null;
            let cropper = null;
            let cropperLoadPromise = null;
            let modalSequence = 0;
            let uploading = false;
            let savedEditorRange = null;
            let modalOpener = null;

            const messages = {
                uploadDisabled: @json(__('admin.article_editor.error.upload_disabled')),
                imageRequired: @json(__('admin.article_editor.error.image_required')),
                imageInvalid: @json(__('admin.article_editor.error.image_invalid')),
                uploadFailed: @json(__('admin.article_editor.error.upload_failed_generic')),
                cropUnavailable: @json(__('admin.article_editor.error.crop_unavailable')),
                uploading: @json(__('admin.article_editor.message.uploading')),
                uploadSuccess: @json(__('admin.article_editor.message.upload_success')),
                copyEmpty: @json(__('admin.article_editor.copy.empty')),
                copySuccess: @json(__('admin.article_editor.copy.success')),
                copyFailed: @json(__('admin.article_editor.copy.failed')),
                wechatCopying: @json(__('admin.article_editor.wechat.copying')),
                wechatSuccess: @json(__('admin.article_editor.wechat.success')),
                wechatFailed: @json(__('admin.article_editor.wechat.failed')),
            };
            const snippets = {
                heading: @json(__('admin.article_editor.snippets.heading')),
                quote: @json(__('admin.article_editor.snippets.quote')),
                list: @json(__('admin.article_editor.snippets.list')),
                divider: @json(__('admin.article_editor.snippets.divider')),
            };

            if (!textarea || !editorNode || typeof Vditor === 'undefined') {
                return;
            }

            function setStatus(message, tone) {
                if (!statusNode) {
                    return;
                }
                statusNode.textContent = message || '';
                statusNode.dataset.tone = tone || '';
            }

            function getRangeContainer(range) {
                if (!range) {
                    return null;
                }

                return range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
                    ? range.commonAncestorContainer
                    : range.commonAncestorContainer.parentElement;
            }

            function isEditorRange(range) {
                const container = getRangeContainer(range);
                return Boolean(container && editorNode.contains(container));
            }

            function saveEditorRange() {
                const selection = window.getSelection();
                if (!selection || selection.rangeCount === 0) {
                    return;
                }

                const range = selection.getRangeAt(0);
                if (!isEditorRange(range)) {
                    return;
                }

                savedEditorRange = range.cloneRange();
            }

            function restoreEditorRange() {
                if (
                    !savedEditorRange
                    || !document.contains(savedEditorRange.startContainer)
                    || !document.contains(savedEditorRange.endContainer)
                    || !isEditorRange(savedEditorRange)
                ) {
                    editor.focus();
                    return false;
                }

                editor.focus();
                const selection = window.getSelection();
                if (!selection) {
                    return false;
                }

                selection.removeAllRanges();
                selection.addRange(savedEditorRange);

                return true;
            }

            function showEditorTip(message) {
                if (!message) {
                    return;
                }

                if (editor && typeof editor.tip === 'function') {
                    editor.tip(message, 2600);
                    return;
                }

                setStatus(message, 'error');
            }

            function getCurrentMarkdown() {
                if (editor && typeof editor.getValue === 'function') {
                    return editor.getValue() || '';
                }

                return textarea.value || '';
            }

            function codePointOffsetToCodeUnit(value, offset) {
                const characters = Array.from(String(value || ''));
                const boundedOffset = Math.max(0, Math.min(characters.length, Number(offset) || 0));

                return characters.slice(0, boundedOffset).join('').length;
            }

            function sourceOccurrenceIndex(source, quote, startOffset, endOffset) {
                const haystack = String(source || '');
                const needle = String(quote || '').trim();
                if (!needle) {
                    return null;
                }

                const start = codePointOffsetToCodeUnit(haystack, startOffset);
                const end = codePointOffsetToCodeUnit(haystack, endOffset);
                if (end <= start || haystack.slice(start, end) !== needle) {
                    return null;
                }

                let occurrence = 0;
                let cursor = haystack.indexOf(needle);
                while (cursor >= 0 && cursor < start) {
                    occurrence += 1;
                    cursor = haystack.indexOf(needle, cursor + needle.length);
                }

                return cursor === start ? occurrence : null;
            }

            function findTextRangeByOccurrence(root, quote, occurrenceIndex = null) {
                const needle = String(quote || '').trim();
                if (!root || !needle) {
                    return null;
                }

                const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
                const nodes = [];
                let text = '';
                let node = walker.nextNode();
                while (node) {
                    const value = node.nodeValue || '';
                    nodes.push({ node, start: text.length, end: text.length + value.length });
                    text += value;
                    node = walker.nextNode();
                }

                const matches = [];
                let cursor = text.indexOf(needle);
                while (cursor >= 0) {
                    matches.push(cursor);
                    cursor = text.indexOf(needle, cursor + needle.length);
                }
                const start = occurrenceIndex === null
                    ? (matches.length === 1 ? matches[0] : -1)
                    : (matches[occurrenceIndex] ?? -1);
                if (start < 0) {
                    return null;
                }
                const end = start + needle.length;
                const startNode = nodes.find((item) => item.start <= start && item.end >= start);
                const endNode = nodes.find((item) => item.start < end && item.end >= end);
                if (!startNode || !endNode) {
                    return null;
                }

                const range = document.createRange();
                range.setStart(startNode.node, start - startNode.start);
                range.setEnd(endNode.node, end - endNode.start);

                return range;
            }

            function revealRange(startOffset, endOffset, quote) {
                const mode = editor?.getCurrentMode?.() || 'ir';
                const root = editor?.vditor?.[mode]?.element;
                const occurrenceIndex = sourceOccurrenceIndex(getCurrentMarkdown(), quote, startOffset, endOffset);
                const range = findTextRangeByOccurrence(root, quote, occurrenceIndex);
                if (!range) {
                    return false;
                }

                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                const target = range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
                    ? range.commonAncestorContainer
                    : range.commonAncestorContainer.parentElement;
                target?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
                target?.classList?.add('ai-quality-located');
                window.setTimeout(() => target?.classList?.remove('ai-quality-located'), 2600);
                editor?.focus?.();

                return true;
            }

            function revealFormField(field, quote, startOffset, endOffset) {
                const fieldIds = {
                    title: 'title',
                    excerpt: 'excerpt',
                    keywords: 'keywords',
                    meta_description: 'meta_description',
                };
                const node = document.getElementById(fieldIds[field] || '');
                if (!node) {
                    return false;
                }

                const value = String(node.value || '');
                const needle = String(quote || '').trim();
                const storedStart = codePointOffsetToCodeUnit(value, startOffset);
                const storedEnd = codePointOffsetToCodeUnit(value, endOffset);
                const matchesStoredRange = storedEnd > storedStart && value.slice(storedStart, storedEnd) === needle;
                const firstMatch = value.indexOf(needle);
                const start = matchesStoredRange
                    ? storedStart
                    : (firstMatch >= 0 && value.indexOf(needle, firstMatch + needle.length) < 0 ? firstMatch : -1);
                if (start < 0) {
                    return false;
                }
                node.focus({ preventScroll: true });
                node.setSelectionRange?.(start, start + needle.length);
                node.scrollIntoView({ behavior: 'smooth', block: 'center' });
                node.classList.add('ai-quality-located');
                window.setTimeout(() => node.classList.remove('ai-quality-located'), 2600);

                return true;
            }

            function copyWithFallback(value) {
                const helper = document.createElement('textarea');
                helper.value = value;
                helper.setAttribute('readonly', 'readonly');
                helper.style.position = 'fixed';
                helper.style.left = '-9999px';
                helper.style.top = '0';
                document.body.appendChild(helper);
                helper.select();
                helper.setSelectionRange(0, helper.value.length);

                try {
                    return document.execCommand('copy');
                } finally {
                    helper.remove();
                }
            }

            async function copyArticleMarkdown() {
                const markdown = getCurrentMarkdown();
                textarea.value = markdown;

                if (!markdown.trim()) {
                    showEditorTip(messages.copyEmpty);
                    return;
                }

                try {
                    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
                        await navigator.clipboard.writeText(markdown);
                    } else if (!copyWithFallback(markdown)) {
                        throw new Error(messages.copyFailed);
                    }

                    showEditorTip(messages.copySuccess);
                } catch (error) {
                    showEditorTip(error.message || messages.copyFailed);
                }
            }

            function copyHtmlWithFallback(html) {
                const helper = document.createElement('div');
                helper.setAttribute('contenteditable', 'true');
                helper.style.position = 'fixed';
                helper.style.left = '-9999px';
                helper.style.top = '0';
                helper.style.width = '720px';
                helper.innerHTML = html;
                document.body.appendChild(helper);
                helper.focus();

                const selection = window.getSelection();
                const range = document.createRange();
                range.selectNodeContents(helper);
                selection?.removeAllRanges();
                selection?.addRange(range);

                try {
                    return document.execCommand('copy');
                } finally {
                    selection?.removeAllRanges();
                    helper.remove();
                }
            }

            async function copyRichHtml(html, plainText) {
                if (
                    navigator.clipboard
                    && typeof navigator.clipboard.write === 'function'
                    && typeof window.ClipboardItem !== 'undefined'
                    && window.isSecureContext
                ) {
                    await navigator.clipboard.write([
                        new ClipboardItem({
                            'text/html': new Blob([html], { type: 'text/html' }),
                            'text/plain': new Blob([plainText || html], { type: 'text/plain' }),
                        }),
                    ]);
                    return;
                }

                if (!copyHtmlWithFallback(html)) {
                    throw new Error(messages.wechatFailed);
                }
            }

            async function copyWeChatHtml() {
                const markdown = getCurrentMarkdown();
                textarea.value = markdown;

                if (!markdown.trim()) {
                    showEditorTip(messages.copyEmpty);
                    return;
                }
                if (!wechatHtmlUrl) {
                    showEditorTip(messages.wechatFailed);
                    return;
                }

                const originalHtml = copyWechatHtmlButton?.innerHTML || '';
                if (copyWechatHtmlButton) {
                    copyWechatHtmlButton.disabled = true;
                    copyWechatHtmlButton.setAttribute('aria-busy', 'true');
                    copyWechatHtmlButton.innerHTML = '<i data-lucide="loader-2" class="mr-1.5 h-4 w-4 animate-spin"></i>' + messages.wechatCopying;
                    window.GeoFlowAdminUi?.refreshIcons?.(copyWechatHtmlButton);
                }

                try {
                    const response = await fetch(wechatHtmlUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ content: markdown }),
                    });
                    const payload = await response.json().catch(function () {
                        return {};
                    });
                    if (!response.ok || !payload.html) {
                        throw new Error(payload.message || messages.wechatFailed);
                    }

                    await copyRichHtml(String(payload.html), String(payload.plain || markdown));
                    showEditorTip(payload.message || messages.wechatSuccess);
                } catch (error) {
                    showEditorTip(error.message || messages.wechatFailed);
                } finally {
                    if (copyWechatHtmlButton) {
                        copyWechatHtmlButton.disabled = false;
                        copyWechatHtmlButton.removeAttribute('aria-busy');
                        copyWechatHtmlButton.innerHTML = originalHtml;
                        window.GeoFlowAdminUi?.refreshIcons?.(copyWechatHtmlButton);
                    }
                }
            }

            function hideContextMenu() {
                if (contextMenu) {
                    contextMenu.hidden = true;
                }
            }

            function showContextMenu(event) {
                if (!contextMenu) {
                    return;
                }

                saveEditorRange();
                const menuWidth = 220;
                const menuHeight = 260;
                const left = Math.min(event.clientX, window.innerWidth - menuWidth - 12);
                const top = Math.min(event.clientY, window.innerHeight - menuHeight - 12);
                contextMenu.style.left = Math.max(12, left) + 'px';
                contextMenu.style.top = Math.max(12, top) + 'px';
                contextMenu.hidden = false;

                window.GeoFlowAdminUi?.refreshIcons?.(contextMenu);
            }

            function destroyCropper() {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            }

            function ensureCropperLoaded() {
                if (typeof window.Cropper !== 'undefined') {
                    return Promise.resolve(window.Cropper);
                }
                if (cropperLoadPromise) {
                    return cropperLoadPromise;
                }

                cropperLoadPromise = new Promise(function (resolve, reject) {
                    const script = document.createElement('script');
                    script.src = cropperScriptUrl;
                    script.async = true;
                    script.onload = function () {
                        if (typeof window.Cropper !== 'undefined') {
                            resolve(window.Cropper);
                            return;
                        }
                        reject(new Error(messages.cropUnavailable));
                    };
                    script.onerror = function () {
                        reject(new Error(messages.cropUnavailable));
                    };
                    document.head.appendChild(script);
                }).catch(function (error) {
                    cropperLoadPromise = null;
                    throw error;
                });

                return cropperLoadPromise;
            }

            function closeModal(options = {}) {
                modalSequence++;
                destroyCropper();
                if (currentObjectUrl) {
                    URL.revokeObjectURL(currentObjectUrl);
                    currentObjectUrl = null;
                }
                currentFile = null;
                if (cropTarget) {
                    cropTarget.onload = null;
                    cropTarget.removeAttribute('src');
                }
                if (modal) {
                    modal.setAttribute('aria-hidden', 'true');
                }
                document.body.classList.remove('overflow-hidden');
                setStatus('', '');
                const focusTarget = modalOpener;
                modalOpener = null;
                if (options.restoreFocus !== false) focusTarget?.focus?.({ preventScroll: true });
            }

            function openImageModal(file) {
                saveEditorRange();
                if (!uploadEnabled) {
                    return messages.uploadDisabled;
                }
                if (!file || !file.type || !file.type.startsWith('image/')) {
                    return messages.imageInvalid;
                }

                closeModal({ restoreFocus: false });
                modalOpener = document.activeElement;
                const sequence = ++modalSequence;
                currentFile = file;
                currentObjectUrl = URL.createObjectURL(file);
                if (altInput) {
                    altInput.value = file.name ? file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ') : '';
                }
                if (cropTarget) {
                    cropTarget.src = currentObjectUrl;
                    cropTarget.onload = async function () {
                        destroyCropper();
                        try {
                            const CropperConstructor = await ensureCropperLoaded();
                            if (sequence !== modalSequence || !currentFile || cropTarget.src !== currentObjectUrl) {
                                return;
                            }
                            cropper = new CropperConstructor(cropTarget, {
                                autoCropArea: 0.88,
                                background: false,
                                viewMode: 1,
                            });
                        } catch (error) {
                            setStatus(error.message || messages.cropUnavailable, 'error');
                        }
                    };
                }
                if (modal) {
                    modal.setAttribute('aria-hidden', 'false');
                }
                document.body.classList.add('overflow-hidden');
                setStatus('', '');
                window.requestAnimationFrame(() => altInput?.focus?.({ preventScroll: true }));

                return null;
            }

            function fileFromCanvas(canvas) {
                return new Promise(function (resolve) {
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            resolve(null);
                            return;
                        }
                        const extension = blob.type === 'image/png' ? 'png' : 'jpg';
                        const baseName = currentFile?.name ? currentFile.name.replace(/\.[^.]+$/, '') : 'article-image';
                        resolve(new File([blob], baseName + '-cropped.' + extension, { type: blob.type || 'image/jpeg' }));
                    }, 'image/jpeg', 0.9);
                });
            }

            function insertMarkdown(markdown) {
                if (!markdown) {
                    return;
                }
                restoreEditorRange();
                editor.insertValue('\n\n' + markdown + '\n\n');
                textarea.value = editor.getValue();
                window.requestAnimationFrame(saveEditorRange);
            }

            function triggerImagePicker() {
                saveEditorRange();
                if (!uploadEnabled) {
                    showEditorTip(messages.uploadDisabled);
                    return;
                }
                if (!quickImageInput) {
                    return;
                }

                quickImageInput.value = '';
                quickImageInput.click();
            }

            function runEditorAction(action) {
                hideContextMenu();

                if (action === 'image') {
                    triggerImagePicker();
                    return;
                }

                if (snippets[action]) {
                    insertMarkdown(snippets[action]);
                }
            }

            async function uploadImageFile(file) {
                if (!file) {
                    setStatus(messages.imageRequired, 'error');
                    return;
                }

                uploading = true;
                uploadOriginalButton.disabled = true;
                uploadCroppedButton.disabled = true;
                setStatus(messages.uploading, '');

                const formData = new FormData();
                formData.append('image', file);
                formData.append('alt', altInput?.value || '');
                formData.append('position', String((textarea.value || '').length));

                try {
                    const response = await fetch(uploadUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(function () {
                        return {};
                    });
                    if (!response.ok) {
                        throw new Error(payload.message || messages.uploadFailed);
                    }
                    insertMarkdown(payload.image?.markdown || '');
                    setStatus(payload.message || messages.uploadSuccess, 'success');
                    setTimeout(closeModal, 350);
                } catch (error) {
                    setStatus(error.message || messages.uploadFailed, 'error');
                } finally {
                    uploading = false;
                    uploadOriginalButton.disabled = false;
                    uploadCroppedButton.disabled = false;
                }
            }

            editor = new Vditor('content-editor', {
                value: textarea.value || '',
                height: 560,
                mode: 'ir',
                cdn: @json(asset('vendor/vditor')),
                lang: @json($vditorLang),
                cache: {
                    enable: false,
                },
                preview: {
                    markdown: {
                        toc: true,
                    },
                    hljs: {
                        lineNumber: false,
                    },
                },
                toolbar: [
                    'emoji', 'headings', 'bold', 'italic', 'strike', '|',
                    'line', 'quote', 'list', 'ordered-list', 'check', '|',
                    'code', 'inline-code', 'table', 'link', 'upload', '|',
                    'undo', 'redo', 'fullscreen', 'preview', 'both',
                ],
                upload: {
                    accept: 'image/*',
                    multiple: false,
                    max: 10 * 1024 * 1024,
                    handler: async function (files) {
                        saveEditorRange();
                        const file = files && files.length > 0 ? files[0] : null;
                        return openImageModal(file);
                    },
                },
                input: function (value) {
                    textarea.value = value;
                    window.dispatchEvent(new CustomEvent('geo-article-editor-input'));
                    window.requestAnimationFrame(saveEditorRange);
                },
                after: function () {
                    textarea.value = editor.getValue();
                    saveEditorRange();
                    window.geoArticleEditorAssistantBridge = {
                        getValue: getCurrentMarkdown,
                        setValue: function (value) {
                            const markdown = String(value || '');
                            editor.setValue(markdown, true);
                            textarea.value = markdown;
                            window.dispatchEvent(new CustomEvent('geo-article-editor-input'));
                        },
                        tip: showEditorTip,
                        revealRange: revealRange,
                    };
                    window.dispatchEvent(new CustomEvent('geo-article-editor-ready'));
                    window.GeoFlowAdminUi?.refreshIcons?.(editorNode);
                },
            });

            if (form) {
                form.addEventListener('submit', function () {
                    if (editor) {
                        textarea.value = editor.getValue();
                    }
                });
            }

            ['keyup', 'mouseup', 'focusin'].forEach(function (eventName) {
                editorNode.addEventListener(eventName, saveEditorRange);
            });

            document.addEventListener('selectionchange', function () {
                saveEditorRange();
            });

            document.addEventListener('click', async function (event) {
                const button = event.target.closest('[data-ai-quality-locate]');
                if (!button) {
                    return;
                }

                const quote = String(button.dataset.quote || '');
                const field = String(button.dataset.field || 'content');
                const startOffset = Number(button.dataset.startOffset || 0);
                const endOffset = Number(button.dataset.endOffset || 0);
                const located = field === 'content'
                    ? revealRange(startOffset, endOffset, quote)
                    : revealFormField(field, quote, startOffset, endOffset);
                if (located) {
                    showEditorTip(@json(__('admin.articles.ai_quality.locate_success')));
                    return;
                }

                try {
                    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
                        await navigator.clipboard.writeText(quote);
                    } else {
                        copyWithFallback(quote);
                    }
                } catch (error) {
                    void error;
                }
                showEditorTip(@json(__('admin.articles.ai_quality.locate_fallback')));
            });

            editorNode.addEventListener('contextmenu', function (event) {
                if (!event.target.closest('.vditor-ir, .vditor-wysiwyg, .vditor-sv, .vditor-reset')) {
                    return;
                }

                event.preventDefault();
                showContextMenu(event);
            });

            document.querySelectorAll('[data-editor-action]').forEach(function (node) {
                node.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                node.addEventListener('click', function () {
                    saveEditorRange();
                    runEditorAction(node.dataset.editorAction || '');
                });
            });

            copyMarkdownButton?.addEventListener('click', copyArticleMarkdown);
            copyWechatHtmlButton?.addEventListener('click', copyWeChatHtml);

            document.addEventListener('click', function (event) {
                if (!contextMenu || contextMenu.hidden) {
                    return;
                }
                if (contextMenu.contains(event.target)) {
                    return;
                }
                hideContextMenu();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    if (modal?.getAttribute('aria-hidden') === 'false' && !uploading) {
                        closeModal();
                    }
                    hideContextMenu();
                }
                if (event.key !== 'Tab' || modal?.getAttribute('aria-hidden') !== 'false') return;
                const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            quickImageInput?.addEventListener('change', function (event) {
                const file = event.target.files && event.target.files.length > 0 ? event.target.files[0] : null;
                if (file) {
                    openImageModal(file);
                }
                quickImageInput.value = '';
            });

            document.querySelectorAll('[data-image-modal-close]').forEach(function (node) {
                node.addEventListener('click', function (event) {
                    if (uploading) {
                        return;
                    }
                    if (node.classList.contains('article-image-modal__backdrop') && event.target !== node) {
                        return;
                    }
                    closeModal();
                });
            });

            uploadOriginalButton?.addEventListener('click', function () {
                uploadImageFile(currentFile);
            });

            uploadCroppedButton?.addEventListener('click', async function () {
                if (!cropEnabledInput?.checked || !cropper) {
                    uploadImageFile(currentFile);
                    return;
                }
                const canvas = cropper.getCroppedCanvas({
                    maxWidth: 2400,
                    maxHeight: 2400,
                    imageSmoothingQuality: 'high',
                });
                const croppedFile = canvas ? await fileFromCanvas(canvas) : null;
                uploadImageFile(croppedFile || currentFile);
            });
        })();
    </script>
@endpush
