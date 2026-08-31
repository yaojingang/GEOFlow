@extends('admin.layouts.app')

@php
    $isEdit = (bool) ($isEdit ?? false);
    $taskForm = is_array($taskForm ?? null) ? $taskForm : [];
    $hasCategories = (bool) ($hasCategories ?? true);
    $categoryCreateUrl = (string) ($categoryCreateUrl ?? route('admin.categories.create'));
    $t = static fn (string $key, array $replace = []): string => __("admin.$key", $replace);
    $selectedDistributionChannelIds = collect(old('distribution_channel_ids', $taskForm['distribution_channel_ids'] ?? []))
        ->map(static fn ($id): string => (string) $id)
        ->all();
    $distributionChannels = $formOptions['distributionChannels'] ?? [];
    $visibleDistributionChannelLimit = 6;
    $collapsedDistributionChannelCount = collect($distributionChannels)
        ->values()
        ->filter(static fn (array $channel, int $index): bool => $index >= $visibleDistributionChannelLimit && ! in_array((string) ($channel['id'] ?? ''), $selectedDistributionChannelIds, true))
        ->count();
    $selectedKnowledgeBaseIds = collect(old('knowledge_base_ids', $taskForm['knowledge_base_ids'] ?? array_filter([(string) ($taskForm['knowledge_base_id'] ?? '')])))
        ->map(static fn ($id): string => (string) $id)
        ->filter()
        ->unique()
        ->take(5)
        ->values()
        ->all();
    $knowledgeBases = $formOptions['knowledgeBases'] ?? [];
    $visibleKnowledgeBaseLimit = 6;
    $collapsedKnowledgeBaseCount = collect($knowledgeBases)
        ->values()
        ->filter(static fn (array $kb, int $index): bool => $index >= $visibleKnowledgeBaseLimit && ! in_array((string) ($kb['id'] ?? ''), $selectedKnowledgeBaseIds, true))
        ->count();
    $publishScope = (string) old('publish_scope', (string) ($taskForm['publish_scope'] ?? 'local_and_distribution'));
    $distributionStrategy = (string) old('distribution_strategy', (string) ($taskForm['distribution_strategy'] ?? 'broadcast'));
    $distributionChannelsDisabled = $publishScope === 'local_only';
    $selectedTitleLibraryId = (string) old('title_library_id', (string) ($taskForm['title_library_id'] ?? ''));
    $selectedTitleLibrary = collect($formOptions['titleLibraries'] ?? [])->first(
        static fn (array $library): bool => (string) ($library['id'] ?? '') === $selectedTitleLibraryId
    );
    $createdCount = max(0, (int) ($taskForm['created_count'] ?? 0));
    $articleLimit = max(1, (int) old('article_limit', (int) ($taskForm['article_limit'] ?? 10)));
    $plannedRemaining = max(0, $articleLimit - $createdCount);
    $taskFormI18n = [
        'checking' => $t('task_create.readiness.checking'),
        'blockedTitle' => $t('task_create.readiness.dialog_blocked_title'),
        'warningTitle' => $t('task_create.readiness.dialog_warning_title'),
        'requestFailed' => $t('task_create.readiness.request_failed'),
        'knowledgeBaseLimit' => $t('task_create.error.knowledge_base_limit'),
        'distributionCount' => $t('task_create.label.distribution_channel_selected_count', ['count' => '__COUNT__']),
        'knowledgeBaseCount' => $t('task_create.label.knowledge_base_selected_count', ['count' => '__COUNT__', 'max' => 5]),
        'adjustLimit' => $t('task_create.readiness.actions.adjust_limit', ['count' => '__COUNT__']),
        'savePaused' => $t('task_create.readiness.actions.save_paused'),
        'saveExistingPaused' => $t('task_create.readiness.actions.save_existing_paused'),
        'requestFailedIssue' => [
            'code' => 'request_failed',
            'severity' => 'warning',
            'title' => $t('task_create.readiness.issue.request_failed.title'),
            'message' => $t('task_create.readiness.issue.request_failed.message'),
            'impact' => $t('task_create.readiness.issue.request_failed.impact'),
            'suggestions' => [
                $t('task_create.readiness.issue.request_failed.suggestion_1'),
                $t('task_create.readiness.issue.request_failed.suggestion_2'),
            ],
        ],
    ];
    $initialTitleReadinessReport = session('title_readiness_report');
    $qualityEnabled = (bool) old('ai_quality_enabled', (bool) ($taskForm['ai_quality_enabled'] ?? false));
    $qualityPrompts = $formOptions['qualityPrompts'] ?? [];
    $defaultQualityPromptId = (string) (collect($qualityPrompts)->firstWhere('system_managed', true)['id'] ?? ($qualityPrompts[0]['id'] ?? ''));
    $qualityPromptId = (string) old('ai_quality_prompt_id', (string) ($taskForm['ai_quality_prompt_id'] ?? $defaultQualityPromptId));
    $qualityPassScore = max(1, min(100, (int) old('ai_quality_pass_score', (int) ($taskForm['ai_quality_pass_score'] ?? 85))));
    $qualityRetrievalMode = (string) old('ai_quality_retrieval_mode', (string) ($taskForm['ai_quality_retrieval_mode'] ?? ''));
    $qualityAutoOptimizeEnabled = $qualityEnabled && (bool) old('ai_quality_auto_optimize_enabled', (bool) ($taskForm['ai_quality_auto_optimize_enabled'] ?? false));
    $qualityOptimizationLevel = (string) old('ai_quality_optimization_level', (string) ($taskForm['ai_quality_optimization_level'] ?? 'excellent_80'));
    $optimizationStrategies = (array) config('geoflow.ai_quality_optimization_strategies', []);
    $optimizationStrategyOptions = [
        'pass' => ['minimum' => 0, 'label' => $t('task_create.ai_quality.optimization_pass'), 'desc' => $t('task_create.ai_quality.optimization_pass_help')],
        'excellent_80' => ['minimum' => 80, 'label' => $t('task_create.ai_quality.optimization_80'), 'desc' => $t('task_create.ai_quality.optimization_80_help')],
        'excellent_90' => ['minimum' => 90, 'label' => $t('task_create.ai_quality.optimization_90'), 'desc' => $t('task_create.ai_quality.optimization_90_help')],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.tasks.index') }}" aria-label="{{ __('admin.common.back') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? $t('task_edit.page_heading') : $t('task_create.page_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.page_subtitle') }}</p>
                </div>
            </div>
        </div>

        <section class="mb-6 rounded-lg border border-blue-100 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ $t('task_create.engineering.title') }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ $t('task_create.engineering.desc') }}</p>
                </div>
                <span class="inline-flex w-fit items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                    <i data-lucide="workflow" class="mr-1.5 h-3.5 w-3.5"></i>
                    {{ $t('task_create.engineering.badge') }}
                </span>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['icon' => 'map', 'title' => $t('task_create.engineering.prompt_title'), 'desc' => $t('task_create.engineering.prompt_desc')],
                    ['icon' => 'database', 'title' => $t('task_create.engineering.evidence_title'), 'desc' => $t('task_create.engineering.evidence_desc')],
                    ['icon' => 'shield-check', 'title' => $t('task_create.engineering.gate_title'), 'desc' => $t('task_create.engineering.gate_desc')],
                    ['icon' => 'radio-tower', 'title' => $t('task_create.engineering.distribution_title'), 'desc' => $t('task_create.engineering.distribution_desc')],
                ] as $item)
                    <article class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-md bg-white text-blue-600 ring-1 ring-blue-100">
                            <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5"></i>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ $item['title'] }}</h3>
                        <p class="mt-1 text-xs leading-5 text-gray-500">{{ $item['desc'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <div data-task-form-shell class="w-full">
            @if (! $hasCategories)
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-5">
                    <h3 class="text-base font-semibold text-amber-900">{{ $t('task_create.error.no_categories_configured') }}</h3>
                    <p class="mt-2 text-sm text-amber-800">{{ $t('task_create.help.no_categories_configured') }}</p>
                    <div class="mt-4">
                        <a href="{{ $categoryCreateUrl }}" class="inline-flex items-center px-4 py-2 border border-amber-300 rounded-md text-sm font-medium text-amber-900 bg-white hover:bg-amber-100">
                            <i data-lucide="folder-plus" class="w-4 h-4 mr-2"></i>
                            {{ $t('categories.add') }}
                        </a>
                    </div>
                </div>
            @else
            <form
                method="POST"
                action="{{ $isEdit ? route('admin.tasks.update', ['taskId' => $taskId]) : route('admin.tasks.store') }}"
                class="grid grid-cols-1 gap-6 xl:grid-cols-12"
                data-task-form
                data-title-readiness-url="{{ route('admin.tasks.title-readiness') }}"
                data-task-id="{{ $taskId ?? '' }}"
                data-created-count="{{ $createdCount }}"
            >
                @csrf
                @if ($isEdit)
                    @method('PUT')
                    <input type="hidden" name="task_revision" value="{{ (string) ($taskForm['task_revision'] ?? '') }}">
                    <input type="hidden" name="config_version" value="{{ max(1, (int) ($taskForm['ai_quality_config_version'] ?? 1), (int) ($taskForm['ai_quality_policy_version'] ?? 1)) }}">
                @endif

                <div class="bg-white shadow rounded-lg xl:col-span-12">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.basic_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.basic_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <div class="lg:col-span-3">
                                <label for="task_name" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.task_name') }} *</label>
                                <input type="text" name="task_name" id="task_name" required value="{{ old('task_name', (string) ($taskForm['task_name'] ?? '')) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="{{ $t('task_create.placeholder.task_name') }}">
                            </div>
                            <div class="lg:col-span-2">
                                <label for="title_library_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.title_library') }} *</label>
                                <select name="title_library_id" id="title_library_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.select_title_library') }}</option>
                                    @foreach ($formOptions['titleLibraries'] as $library)
                                        <option
                                            value="{{ $library['id'] }}"
                                            data-title-name="{{ $library['name'] }}"
                                            data-title-total="{{ $library['count'] }}"
                                            data-title-used="{{ $library['used'] }}"
                                            data-title-available="{{ $library['available'] }}"
                                            data-title-manage-url="{{ $library['manage_url'] }}"
                                            @selected($selectedTitleLibraryId === (string) $library['id'])
                                        >
                                            {{ $t('task_create.option.library_readiness_count', ['name' => $library['name'], 'available' => $library['available'], 'total' => $library['count']]) }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs leading-5 text-gray-500" data-task-title-stats aria-live="polite">
                                    <span>{{ $t('task_create.readiness.stats.total') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="total">{{ (int) ($selectedTitleLibrary['count'] ?? 0) }}</strong></span>
                                    <span>{{ $t('task_create.readiness.stats.used') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="used">{{ (int) ($selectedTitleLibrary['used'] ?? 0) }}</strong></span>
                                    <span>{{ $t('task_create.readiness.stats.available') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="available">{{ (int) ($selectedTitleLibrary['available'] ?? 0) }}</strong></span>
                                    <span>{{ $t('task_create.readiness.stats.remaining') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="remaining">{{ $plannedRemaining }}</strong></span>
                                </div>
                            </div>
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.task_status') }}</label>
                                <select name="status" id="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="active" @selected(old('status', (string) ($taskForm['status'] ?? 'active')) === 'active')>{{ $t('task_create.option.status_active') }}</option>
                                    <option value="paused" @selected(old('status', (string) ($taskForm['status'] ?? 'active')) === 'paused')>{{ $t('task_create.option.status_paused') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg xl:col-span-12">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.content_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.content_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <div>
                                <label for="prompt_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.content_prompt') }} *</label>
                                <select name="prompt_id" id="prompt_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.select_prompt') }}</option>
                                    @foreach ($formOptions['prompts'] as $prompt)
                                        <option value="{{ $prompt['id'] }}" @selected((string) old('prompt_id', (string) ($taskForm['prompt_id'] ?? '')) === (string) $prompt['id'])>{{ $prompt['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ai_model_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_model') }} *</label>
                                <select name="ai_model_id" id="ai_model_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.select_ai_model') }}</option>
                                    @foreach ($formOptions['aiModels'] as $model)
                                        <option value="{{ $model['id'] }}" @selected((string) old('ai_model_id', (string) ($taskForm['ai_model_id'] ?? '')) === (string) $model['id'])>{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="model_selection_mode" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.model_selection_mode') }}</label>
                                <select name="model_selection_mode" id="model_selection_mode" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="fixed" @selected(old('model_selection_mode', (string) ($taskForm['model_selection_mode'] ?? 'fixed')) === 'fixed')>{{ $t('task_create.option.model_selection_fixed') }}</option>
                                    <option value="smart_failover" @selected(old('model_selection_mode', (string) ($taskForm['model_selection_mode'] ?? 'fixed')) === 'smart_failover')>{{ $t('task_create.option.model_selection_smart_failover') }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.model_selection_mode') !!}</p>
                            </div>
                            <div class="lg:col-span-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.knowledge_bases') }}</label>
                                        <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.knowledge_bases') !!}</p>
                                    </div>
                                    <span data-knowledge-base-count class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                                        {{ $t('task_create.label.knowledge_base_selected_count', ['count' => count($selectedKnowledgeBaseIds), 'max' => 5]) }}
                                    </span>
                                </div>
                                @if (empty($knowledgeBases))
                                    <div class="mt-3 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                        {{ $t('task_create.option.no_knowledge_base') }}
                                    </div>
                                @else
                                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($knowledgeBases as $knowledgeBaseIndex => $kb)
                                            @php
                                                $knowledgeBaseId = (string) $kb['id'];
                                                $knowledgeBaseInitiallyHidden = $knowledgeBaseIndex >= $visibleKnowledgeBaseLimit
                                                    && ! in_array($knowledgeBaseId, $selectedKnowledgeBaseIds, true);
                                            @endphp
                                            <label data-knowledge-base-card @if($knowledgeBaseInitiallyHidden) data-knowledge-base-collapsed="true" @endif
                                                   @class([
                                                       'flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm transition hover:border-blue-300 hover:bg-blue-50',
                                                       'hidden' => $knowledgeBaseInitiallyHidden,
                                                   ])>
                                                <input type="checkbox" name="knowledge_base_ids[]" value="{{ $knowledgeBaseId }}" @checked(in_array($knowledgeBaseId, $selectedKnowledgeBaseIds, true)) data-knowledge-base-input
                                                       class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span class="min-w-0">
                                                    <span class="block font-medium text-gray-900">{{ $kb['name'] }}</span>
                                                    <span class="block text-xs text-gray-500">{{ $t('task_create.help.knowledge_base_card') }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @if ($collapsedKnowledgeBaseCount > 0)
                                        <div class="mt-3">
                                            <button type="button" data-knowledge-base-toggle
                                                    data-expand-label="{{ $t('task_create.button.knowledge_base_expand_more', ['count' => '__COUNT__']) }}"
                                                    data-collapse-label="{{ $t('task_create.button.knowledge_base_collapse') }}"
                                                    class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                                {{ $t('task_create.button.knowledge_base_expand_more', ['count' => $collapsedKnowledgeBaseCount]) }}
                                            </button>
                                        </div>
                                    @endif
                                @endif
                                @error('knowledge_base_ids')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                                @error('knowledge_base_ids.*')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="author_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.author') }}</label>
                                <select name="author_id" id="author_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="0">{{ $t('task_create.option.random_author') }}</option>
                                    @foreach ($formOptions['authors'] as $author)
                                        <option value="{{ $author['id'] }}" @selected((string) old('author_id', (string) ($taskForm['author_id'] ?? '0')) === (string) $author['id'])>{{ $author['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg xl:col-span-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.image_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.image_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        @php
                            $imageCountValue = (string) old('image_count', (string) ($taskForm['image_count'] ?? '1'));
                        @endphp
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="image_library_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_library') }}</label>
                                <select name="image_library_id" id="image_library_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.no_images') }}</option>
                                    @foreach ($formOptions['imageLibraries'] as $library)
                                        <option value="{{ $library['id'] }}" @selected((string) old('image_library_id', (string) ($taskForm['image_library_id'] ?? '')) === (string) $library['id'])>
                                            {{ $t('task_create.option.image_library_count', ['name' => $library['name'], 'count' => $library['count']]) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="image_count" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_count') }}</label>
                                <select name="image_count" id="image_count" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="0" @selected($imageCountValue === '0')>{{ $t('task_create.option.no_image_count') }}</option>
                                    <option value="1" @selected($imageCountValue === '1')>{{ $t('task_create.option.image_count', ['count' => 1]) }}</option>
                                    <option value="2" @selected($imageCountValue === '2')>{{ $t('task_create.option.image_count', ['count' => 2]) }}</option>
                                    <option value="3" @selected($imageCountValue === '3')>{{ $t('task_create.option.image_count', ['count' => 3]) }}</option>
                                    <option value="4" @selected($imageCountValue === '4')>{{ $t('task_create.option.image_count', ['count' => 4]) }}</option>
                                    <option value="5" @selected($imageCountValue === '5')>{{ $t('task_create.option.image_count', ['count' => 5]) }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.image_count') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg xl:col-span-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.publish_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.publish_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="need_review" id="need_review" @checked((bool) old('need_review', (bool) ($taskForm['need_review'] ?? false)))
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="need_review" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.need_review') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.need_review') }}</p>
                            </div>
                            <div>
                                <label for="publish_interval" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.publish_interval') }}</label>
                                <input type="number" name="publish_interval" id="publish_interval" min="1" value="{{ old('publish_interval', (string) ($taskForm['publish_interval'] ?? 60)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.publish_interval') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <section class="bg-white shadow rounded-lg xl:col-span-12" data-ai-quality-card>
                    <div class="flex flex-col gap-4 border-b border-gray-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-600">
                                <i data-lucide="shield-check" class="h-5 w-5"></i>
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.ai_quality.title') }}</h3>
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600" data-ai-quality-state
                                          data-enabled-label="{{ $t('task_create.ai_quality.enabled') }}"
                                          data-disabled-label="{{ $t('task_create.ai_quality.disabled') }}">
                                        {{ $qualityEnabled ? $t('task_create.ai_quality.enabled') : $t('task_create.ai_quality.disabled') }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ $t('task_create.ai_quality.description') }}</p>
                            </div>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center gap-3">
                            <span class="text-sm font-medium text-gray-700">{{ $t('task_create.ai_quality.switch_label') }}</span>
                            <input type="checkbox" name="ai_quality_enabled" id="ai_quality_enabled" value="1" @checked($qualityEnabled)
                                   class="peer sr-only" data-ai-quality-toggle>
                            <span class="relative h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"></span>
                        </label>
                    </div>

                    <div @class(['px-6 py-5 space-y-5', 'hidden' => ! $qualityEnabled]) data-ai-quality-settings>
                        @if (empty($qualityPrompts))
                            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                {{ $t('task_create.ai_quality.no_prompt') }}
                                <a href="{{ route('admin.ai-prompts.index') }}" class="font-semibold underline">{{ $t('task_create.ai_quality.configure_prompt') }}</a>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            <div>
                                <label for="ai_quality_prompt_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.ai_quality.prompt_label') }}</label>
                                <select name="ai_quality_prompt_id" id="ai_quality_prompt_id" data-ai-quality-required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ $t('task_create.ai_quality.prompt_placeholder') }}</option>
                                    @foreach ($qualityPrompts as $qualityPrompt)
                                        <option value="{{ $qualityPrompt['id'] }}" @selected($qualityPromptId === (string) $qualityPrompt['id'])>
                                            {{ $qualityPrompt['name'] }}{{ $qualityPrompt['version'] !== '' ? ' · v'.$qualityPrompt['version'] : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.ai_quality.prompt_help') }}</p>
                                @error('ai_quality_prompt_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="ai_quality_model_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.ai_quality.model_label') }}</label>
                                <select name="ai_quality_model_id" id="ai_quality_model_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ $t('task_create.ai_quality.follow_content_model') }}</option>
                                    @foreach ($formOptions['aiModels'] as $model)
                                        <option value="{{ $model['id'] }}" @selected((string) old('ai_quality_model_id', (string) ($taskForm['ai_quality_model_id'] ?? '')) === (string) $model['id'])>{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.ai_quality.model_help') }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            <div>
                                <label for="ai_quality_pass_score" class="block text-sm font-medium text-gray-700">{{ $t('task_create.ai_quality.pass_score') }}</label>
                                <input type="number" min="1" max="100" name="ai_quality_pass_score" id="ai_quality_pass_score"
                                       value="{{ old('ai_quality_pass_score', (string) ($taskForm['ai_quality_pass_score'] ?? 85)) }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.ai_quality.pass_score_help') }}</p>
                            </div>
                            <div>
                                <label for="ai_quality_manual_override_min_score" class="block text-sm font-medium text-gray-700">{{ $t('task_create.ai_quality.manual_score') }}</label>
                                <input type="number" min="0" max="99" name="ai_quality_manual_override_min_score" id="ai_quality_manual_override_min_score"
                                       value="{{ old('ai_quality_manual_override_min_score', (string) ($taskForm['ai_quality_manual_override_min_score'] ?? 70)) }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.ai_quality.manual_score_help') }}</p>
                            </div>
                        </div>

                        <x-admin.ai-quality-retrieval-selector
                            id="task-ai-quality-retrieval-mode"
                            name="ai_quality_retrieval_mode"
                            :value="$qualityRetrievalMode"
                            :selected-knowledge-base-ids="$selectedKnowledgeBaseIds"
                            :readiness-by-knowledge-base="$formOptions['aiQualityRetrievalReadinessByKnowledgeBase'] ?? []"
                            knowledge-input-selector="[data-knowledge-base-input]"
                            :persisted="$isEdit || old('ai_quality_retrieval_mode') !== null"
                        />

                        <label class="flex gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-4">
                            <input type="checkbox" name="ai_quality_timeout_sampling_enabled" id="ai_quality_timeout_sampling_enabled" value="1"
                                   @checked((bool) old('ai_quality_timeout_sampling_enabled', (bool) ($taskForm['ai_quality_timeout_sampling_enabled'] ?? false)))
                                   class="mt-1 h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500"
                                   data-ai-quality-timeout-sampling>
                            <span>
                                <span class="block text-sm font-medium text-amber-950">{{ $t('task_create.ai_quality.timeout_sampling_label') }}</span>
                                <span class="mt-1 block text-sm leading-6 text-amber-800">{{ $t('task_create.ai_quality.timeout_sampling_help') }}</span>
                            </span>
                        </label>

                        <fieldset class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4" data-ai-quality-optimization>
                            <legend class="sr-only">{{ $t('task_create.ai_quality.optimization_title') }}</legend>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="max-w-3xl">
                                    <p class="text-sm font-semibold text-gray-900">{{ $t('task_create.ai_quality.optimization_title') }}</p>
                                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ $t('task_create.ai_quality.optimization_help') }}</p>
                                </div>
                                <label class="relative inline-flex shrink-0 cursor-pointer items-center gap-3">
                                    <span class="text-sm font-medium text-gray-700">{{ $t('task_create.ai_quality.optimization_switch') }}</span>
                                    <input type="checkbox" name="ai_quality_auto_optimize_enabled" id="ai_quality_auto_optimize_enabled" value="1"
                                           @checked($qualityAutoOptimizeEnabled) class="peer sr-only" data-ai-quality-optimization-toggle>
                                    <span class="relative h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"></span>
                                </label>
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3" data-ai-quality-optimization-options>
                                @foreach ($optimizationStrategyOptions as $strategyValue => $strategy)
                                    @php
                                        $strategyConfig = (array) ($optimizationStrategies[$strategyValue] ?? []);
                                        $strategyRounds = max(1, min(3, (int) ($strategyConfig['max_rounds'] ?? ($strategyValue === 'pass' ? 1 : ($strategyValue === 'excellent_80' ? 2 : 3)))));
                                        $actualTarget = max($qualityPassScore, (int) $strategy['minimum']);
                                    @endphp
                                    <label class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                        <input type="radio" name="ai_quality_optimization_level" value="{{ $strategyValue }}"
                                               @checked($qualityOptimizationLevel === $strategyValue)
                                               class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
                                               data-ai-quality-optimization-level data-minimum-target="{{ $strategy['minimum'] }}">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-900">{{ $strategy['label'] }}</span>
                                            <span class="mt-1 block text-xs leading-5 text-gray-600">{{ $strategy['desc'] }}</span>
                                            <span class="mt-2 block text-xs font-medium text-blue-700"
                                                  data-ai-quality-optimization-target
                                                  data-target-template="{{ $t('task_create.ai_quality.optimization_actual_target', ['score' => '__SCORE__']) }}">{{ $t('task_create.ai_quality.optimization_actual_target', ['score' => $actualTarget]) }}</span>
                                            <span class="mt-1 block text-xs text-gray-500">{{ $t('task_create.ai_quality.optimization_rounds', ['rounds' => $strategyRounds, 'steps' => $strategyRounds * 2]) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-3 text-xs leading-5 text-gray-500">{{ $t('task_create.ai_quality.optimization_sampling_note') }}</p>
                        </fieldset>

                        <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ $t('task_create.ai_quality.workflow_title') }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-gray-700" data-ai-quality-workflow
                                 data-manual-label="{{ $t('task_create.ai_quality.workflow_manual') }}"
                                 data-auto-label="{{ $t('task_create.ai_quality.workflow_auto') }}">
                                <span class="rounded-md bg-white px-3 py-2 shadow-sm">{{ $t('task_create.ai_quality.workflow_generate') }}</span>
                                <i data-lucide="arrow-right" class="h-4 w-4 text-gray-400"></i>
                                <span class="rounded-md bg-white px-3 py-2 font-medium text-blue-700 shadow-sm">{{ $t('task_create.ai_quality.workflow_inspect') }}</span>
                                <i data-lucide="arrow-right" class="h-4 w-4 text-gray-400"></i>
                                <span @class(['rounded-md bg-white px-3 py-2 font-medium text-blue-700 shadow-sm', 'hidden' => ! $qualityAutoOptimizeEnabled]) data-ai-quality-workflow-optimization>{{ $t('task_create.ai_quality.workflow_optimize') }}</span>
                                <i @class(['h-4 w-4 text-gray-400', 'hidden' => ! $qualityAutoOptimizeEnabled]) data-ai-quality-workflow-optimization data-lucide="arrow-right"></i>
                                <span class="rounded-md bg-white px-3 py-2 shadow-sm" data-ai-quality-workflow-tail>{{ $t('task_create.ai_quality.workflow_manual') }}</span>
                            </div>
                            <p class="mt-3 text-xs leading-5 text-blue-800">{{ $t('task_create.ai_quality.workflow_help') }}</p>
                        </div>
                    </div>
                </section>

                <div class="bg-white shadow rounded-lg xl:col-span-12">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.distribution_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.distribution_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <fieldset class="mb-5">
                            <legend class="text-sm font-medium text-gray-900">{{ $t('task_create.distribution.scope_title') }}</legend>
                            <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.distribution.scope_help') }}</p>
                            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="local_and_distribution" @checked($publishScope === 'local_and_distribution') data-publish-scope-option class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_local_and_distribution') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_local_and_distribution_desc') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="distribution_only" @checked($publishScope === 'distribution_only') data-publish-scope-option class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_distribution_only') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_distribution_only_desc') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="local_only" @checked($publishScope === 'local_only') data-publish-scope-option class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_local_only') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_local_only_desc') }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        @if (empty($distributionChannels))
                            <div class="rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                {{ $t('task_create.distribution.empty') }}
                                @if ($canManageProtectedWorkflows ?? false)
                                    <a href="{{ route('admin.distribution.create') }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $t('task_create.distribution.create_link') }}</a>
                                @endif
                            </div>
                        @else
                            <fieldset class="mb-5">
                                <legend class="text-sm font-medium text-gray-900">{{ $t('task_create.distribution.strategy_title') }}</legend>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.distribution.strategy_help') }}</p>
                                <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                    <label data-distribution-strategy-card @class([
                                        'flex gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm transition',
                                        'cursor-pointer hover:border-blue-300 hover:bg-blue-50' => ! $distributionChannelsDisabled,
                                        'cursor-not-allowed bg-gray-50 opacity-50' => $distributionChannelsDisabled,
                                    ])>
                                        <input type="radio" name="distribution_strategy" value="broadcast" @checked($distributionStrategy === 'broadcast') @disabled($distributionChannelsDisabled) data-distribution-strategy-input class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        <span>
                                            <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.strategy_broadcast') }}</span>
                                            <span class="block text-gray-500">{{ $t('task_create.distribution.strategy_broadcast_desc') }}</span>
                                        </span>
                                    </label>
                                    <label data-distribution-strategy-card @class([
                                        'flex gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm transition',
                                        'cursor-pointer hover:border-blue-300 hover:bg-blue-50' => ! $distributionChannelsDisabled,
                                        'cursor-not-allowed bg-gray-50 opacity-50' => $distributionChannelsDisabled,
                                    ])>
                                        <input type="radio" name="distribution_strategy" value="round_robin" @checked($distributionStrategy === 'round_robin') @disabled($distributionChannelsDisabled) data-distribution-strategy-input class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        <span>
                                            <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.strategy_round_robin') }}</span>
                                            <span class="block text-gray-500">{{ $t('task_create.distribution.strategy_round_robin_desc') }}</span>
                                        </span>
                                    </label>
                                    <label data-distribution-strategy-card @class([
                                        'flex gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm transition',
                                        'cursor-pointer hover:border-blue-300 hover:bg-blue-50' => ! $distributionChannelsDisabled,
                                        'cursor-not-allowed bg-gray-50 opacity-50' => $distributionChannelsDisabled,
                                    ])>
                                        <input type="radio" name="distribution_strategy" value="random_balanced" @checked($distributionStrategy === 'random_balanced') @disabled($distributionChannelsDisabled) data-distribution-strategy-input class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        <span>
                                            <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.strategy_random_balanced') }}</span>
                                            <span class="block text-gray-500">{{ $t('task_create.distribution.strategy_random_balanced_desc') }}</span>
                                        </span>
                                    </label>
                                </div>
                                @error('distribution_strategy')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </fieldset>

                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-medium text-gray-900">{{ $t('task_create.distribution.channels_title') }}</h4>
                                    <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.distribution.channels_help') }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span data-distribution-channel-count class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
                                        {{ $t('task_create.label.distribution_channel_selected_count', ['count' => count($selectedDistributionChannelIds)]) }}
                                    </span>
                                    <button type="button" data-distribution-channel-select-all @disabled($distributionChannelsDisabled)
                                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                                        {{ $t('task_create.button.distribution_channel_select_all') }}
                                    </button>
                                    <button type="button" data-distribution-channel-clear @disabled($distributionChannelsDisabled)
                                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                                        {{ $t('task_create.button.distribution_channel_clear') }}
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($distributionChannels as $index => $channel)
                                    @php
                                        $channelId = (string) $channel['id'];
                                        $channelInitiallyHidden = $index >= $visibleDistributionChannelLimit
                                            && ! in_array($channelId, $selectedDistributionChannelIds, true);
                                    @endphp
                                    <label data-distribution-channel-card @if($index >= $visibleDistributionChannelLimit) data-distribution-channel-collapsed="true" @endif @class([
                                        'flex items-start gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm transition',
                                        'cursor-pointer hover:border-blue-300 hover:bg-blue-50' => ! $distributionChannelsDisabled,
                                        'cursor-not-allowed bg-gray-50 opacity-50' => $distributionChannelsDisabled,
                                        'hidden' => $channelInitiallyHidden,
                                    ])>
                                        <input type="checkbox" name="distribution_channel_ids[]" value="{{ $channelId }}" @checked(! $distributionChannelsDisabled && in_array($channelId, $selectedDistributionChannelIds, true)) @disabled($distributionChannelsDisabled) data-distribution-channel-input
                                               class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        <span class="min-w-0">
                                            <span class="block font-medium text-gray-900">{{ $channel['name'] }}</span>
                                            <span class="block break-all text-gray-500">{{ $channel['domain'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @if ($collapsedDistributionChannelCount > 0)
                                <div class="mt-3">
                                    <button type="button" data-distribution-channel-toggle
                                            data-expand-label="{{ $t('task_create.button.distribution_channel_expand_more', ['count' => '__COUNT__']) }}"
                                            data-collapse-label="{{ $t('task_create.button.distribution_channel_collapse') }}"
                                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                        {{ $t('task_create.button.distribution_channel_expand_more', ['count' => $collapsedDistributionChannelCount]) }}
                                    </button>
                                </div>
                            @endif
                            <p class="mt-3 text-sm text-gray-500">{{ $t('task_create.distribution.help') }}</p>
                        @endif
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg xl:col-span-12">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.seo_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.seo_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_keywords" id="auto_keywords" @checked(old('auto_keywords', (string) ($taskForm['auto_keywords'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="auto_keywords" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.auto_keywords') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.auto_keywords') }}</p>
                            </div>
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_description" id="auto_description" @checked(old('auto_description', (string) ($taskForm['auto_description'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="auto_description" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.auto_description') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.auto_description') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg xl:col-span-8">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.category_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.category_desc') }}</p>
                    </div>
                    @php
                        $categoryMode = (string) old('category_mode', (string) ($taskForm['category_mode'] ?? 'smart'));
                    @endphp
                    <div class="px-6 py-4 space-y-4">
                        <div>
                            <label class="text-base font-medium text-gray-900">{{ $t('task_create.field.category_mode') }}</label>
                            <p class="text-sm leading-5 text-gray-500">{{ $t('task_create.help.category_mode') }}</p>
                            <fieldset class="mt-4">
                                <legend class="sr-only">{{ $t('task_create.field.category_mode') }}</legend>
                                <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                                    <div class="flex items-start rounded-md border border-gray-200 px-4 py-3">
                                        <div class="flex items-center h-5">
                                            <input id="category_smart" name="category_mode" type="radio" value="smart" @checked($categoryMode === 'smart')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_smart" class="font-medium text-gray-700">{{ $t('task_create.option.category_smart') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_smart') }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start rounded-md border border-gray-200 px-4 py-3">
                                        <div class="flex items-center h-5">
                                            <input id="category_fixed" name="category_mode" type="radio" value="fixed" @checked($categoryMode === 'fixed')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_fixed" class="font-medium text-gray-700">{{ $t('task_create.option.category_fixed') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_fixed') }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start rounded-md border border-gray-200 px-4 py-3">
                                        <div class="flex items-center h-5">
                                            <input id="category_random" name="category_mode" type="radio" value="random" @checked($categoryMode === 'random')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_random" class="font-medium text-gray-700">{{ $t('task_create.option.category_random') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_random') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                        <div id="fixed-category-section" class="hidden">
                            <label for="fixed_category_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.fixed_category') }}</label>
                            <select name="fixed_category_id" id="fixed_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">{{ $t('task_create.option.select_category') }}</option>
                                @foreach ($formOptions['categories'] as $category)
                                    <option value="{{ $category['id'] }}" @selected((string) old('fixed_category_id', (string) ($taskForm['fixed_category_id'] ?? '')) === (string) $category['id'])>{{ $category['name'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-sm text-gray-500">{{ $t('task_create.help.fixed_category') }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-gray-900 mb-2">{{ $t('task_create.preview.categories_title') }}</h4>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($formOptions['categories'] as $category)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ $category['name'] }}</span>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ $t('task_create.preview.categories_count', ['count' => count($formOptions['categories'])]) }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg xl:col-span-4">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.advanced_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.advanced_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="article_limit" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.article_limit') }}</label>
                                <input type="number" name="article_limit" id="article_limit" min="1" max="99999" required value="{{ old('article_limit', (string) ($taskForm['article_limit'] ?? 10)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.article_limit') }}</p>
                            </div>
                            <div>
                                <label for="draft_limit" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.draft_limit') }}</label>
                                <input type="number" name="draft_limit" id="draft_limit" min="1" value="{{ old('draft_limit', (string) ($taskForm['draft_limit'] ?? 10)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.draft_limit') }}</p>
                            </div>
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="is_loop" id="is_loop" @checked(old('is_loop', (string) ($taskForm['is_loop'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_loop" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.loop_mode') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.loop_mode') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-4 xl:col-span-12">
                    <a href="{{ route('admin.tasks.index') }}" class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        {{ __('admin.button.cancel') }}
                    </a>
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-md border border-transparent bg-blue-600 px-6 py-2 text-sm font-medium text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] disabled:cursor-wait disabled:bg-blue-400" data-task-form-submit disabled aria-disabled="true">
                        <span data-task-form-submit-label>{{ $isEdit ? __('admin.task_edit.button.save_changes') : __('admin.button.create_task') }}</span>
                    </button>
                </div>
            </form>
            @endif
        </div>
    </div>

    @if ($hasCategories)
        <dialog
            class="fixed inset-0 m-auto w-[min(600px,calc(100vw-2rem))] max-w-none overflow-hidden overscroll-contain rounded-2xl border-0 bg-white p-0 text-left text-gray-900 shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-[rgba(15,23,42,0.48)]"
            data-task-title-readiness-dialog
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="task-title-readiness-title"
            aria-describedby="task-title-readiness-summary task-title-readiness-recommendation"
        >
            <div class="flex max-h-[min(760px,calc(100dvh-2rem))] flex-col">
                <header class="flex items-start gap-4 px-6 pb-5 pt-6 max-[520px]:px-5">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600" data-task-readiness-icon-wrap aria-hidden="true">
                        <i data-lucide="triangle-alert" class="h-5 w-5" data-task-readiness-icon></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500">{{ $t('task_create.readiness.dialog_eyebrow') }}</p>
                        <h2 id="task-title-readiness-title" class="mt-1 text-xl font-semibold leading-7 text-gray-900 text-balance" data-task-readiness-title>{{ $t('task_create.readiness.dialog_blocked_title') }}</h2>
                    </div>
                    <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 [@media(hover:hover)]:hover:text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:scale-[.96]" data-task-readiness-close aria-label="{{ $t('task_create.readiness.actions.close') }}">
                        <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="grid grid-cols-4 divide-x divide-gray-200 border-y border-gray-200 bg-gray-50 max-[520px]:grid-cols-2 max-[520px]:divide-x-0">
                    @foreach ([
                        'remaining' => $t('task_create.readiness.stats.remaining'),
                        'total' => $t('task_create.readiness.stats.total'),
                        'used' => $t('task_create.readiness.stats.used'),
                        'available' => $t('task_create.readiness.stats.available'),
                    ] as $statKey => $statLabel)
                        <div class="px-4 py-3 max-[520px]:border-b max-[520px]:border-gray-200 max-[520px]:px-5">
                            <p class="text-[11px] font-medium leading-4 text-gray-500">{{ $statLabel }}</p>
                            <p class="mt-0.5 text-lg font-semibold leading-6 text-gray-900 tabular-nums" data-task-readiness-stat="{{ $statKey }}">0</p>
                        </div>
                    @endforeach
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-5 max-[520px]:px-5">
                    <p id="task-title-readiness-summary" class="text-sm leading-6 text-gray-700 text-pretty" data-task-readiness-summary></p>
                    <div class="mt-5 space-y-3" data-task-readiness-issues></div>
                    <div class="mt-5 rounded-xl bg-gray-50 px-4 py-3.5">
                        <p id="task-title-readiness-recommendation" class="text-sm leading-6 text-gray-700 text-pretty" data-task-readiness-recommendation></p>
                        <p class="mt-2 hidden text-sm font-medium leading-6 text-amber-800" data-task-readiness-paused-hint hidden></p>
                    </div>
                </div>

                <footer class="flex flex-wrap justify-end gap-2.5 border-t border-gray-100 bg-gray-50 px-6 py-4 max-[520px]:flex-col max-[520px]:px-5">
                    <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-close>{{ $t('task_create.readiness.actions.close') }}</button>
                    <a href="#" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-[background-color,border-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-manage>{{ $t('task_create.readiness.actions.manage_library') }}</a>
                    <button type="button" class="hidden inline-flex min-h-10 items-center justify-center rounded-lg border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-900 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-adjust hidden></button>
                    <button type="button" class="hidden inline-flex min-h-10 items-center justify-center rounded-lg border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-900 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-loop hidden>{{ $t('task_create.readiness.actions.enable_loop') }}</button>
                    <button type="button" class="hidden inline-flex min-h-10 items-center justify-center rounded-lg bg-gray-800 px-4 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-600 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-pause hidden>{{ $t('task_create.readiness.actions.save_paused') }}</button>
                    <button type="button" class="hidden inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-acknowledge hidden>{{ $t('task_create.readiness.actions.acknowledge') }}</button>
                    <button type="button" class="hidden inline-flex min-h-10 items-center justify-center rounded-lg border border-blue-300 bg-blue-50 px-4 text-sm font-semibold text-blue-800 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-retry hidden>{{ $t('task_create.readiness.actions.retry') }}</button>
                    <button type="button" class="hidden inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 active:scale-[.96] max-[520px]:w-full" data-task-readiness-server hidden>{{ $t('task_create.readiness.actions.server_check') }}</button>
                </footer>
            </div>
        </dialog>

        <script type="application/json" data-task-form-i18n>@json($taskFormI18n)</script>
        <script type="application/json" data-task-title-readiness-initial>@json($initialTitleReadinessReport)</script>
    @endif
@endsection
