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
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.tasks.index') }}" class="text-gray-400 hover:text-gray-600">
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
            <form method="POST" action="{{ $isEdit ? route('admin.tasks.update', ['taskId' => $taskId]) : route('admin.tasks.store') }}" class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                @csrf
                @if ($isEdit)
                    @method('PUT')
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
                                        <option value="{{ $library['id'] }}" @selected((string) old('title_library_id', (string) ($taskForm['title_library_id'] ?? '')) === (string) $library['id'])>
                                            {{ $t('task_create.option.library_count', ['name' => $library['name'], 'count' => $library['count']]) }}
                                        </option>
                                    @endforeach
                                </select>
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
                                            @php($knowledgeBaseId = (string) $kb['id'])
                                            @php($knowledgeBaseInitiallyHidden = $knowledgeBaseIndex >= $visibleKnowledgeBaseLimit && ! in_array($knowledgeBaseId, $selectedKnowledgeBaseIds, true))
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
                        @php($imageCountValue = (string) old('image_count', (string) ($taskForm['image_count'] ?? '1')))
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
                                <a href="{{ route('admin.distribution.create') }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $t('task_create.distribution.create_link') }}</a>
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
                                    @php($channelId = (string) $channel['id'])
                                    @php($channelInitiallyHidden = $index >= $visibleDistributionChannelLimit && ! in_array($channelId, $selectedDistributionChannelIds, true))
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
                    @php($categoryMode = (string) old('category_mode', (string) ($taskForm['category_mode'] ?? 'smart')))
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
                                <input type="number" name="article_limit" id="article_limit" min="1" value="{{ old('article_limit', (string) ($taskForm['article_limit'] ?? 10)) }}"
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
                    <button type="submit" class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                        {{ $isEdit ? __('admin.task_edit.button.save_changes') : __('admin.button.create_task') }}
                    </button>
                </div>
            </form>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const isEditMode = @json($isEdit);

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            const imageLibrarySelect = document.getElementById('image_library_id');
            const imageCountSelect = document.getElementById('image_count');
            const needReviewCheckbox = document.getElementById('need_review');
            const publishIntervalInput = document.getElementById('publish_interval');
            const articleLimitInput = document.getElementById('article_limit');
            const draftLimitInput = document.getElementById('draft_limit');
            const fixedCategorySection = document.getElementById('fixed-category-section');
            const fixedCategorySelect = document.getElementById('fixed_category_id');
            const categoryModeRadios = document.querySelectorAll('input[name="category_mode"]');
            const publishScopeRadios = document.querySelectorAll('[data-publish-scope-option]');
            const distributionChannelInputs = document.querySelectorAll('[data-distribution-channel-input]');
            const distributionStrategyInputs = document.querySelectorAll('[data-distribution-strategy-input]');
            const distributionStrategyCards = document.querySelectorAll('[data-distribution-strategy-card]');
            const distributionChannelCount = document.querySelector('[data-distribution-channel-count]');
            const distributionChannelToggle = document.querySelector('[data-distribution-channel-toggle]');
            const collapsedDistributionChannelCards = document.querySelectorAll('[data-distribution-channel-collapsed="true"]');
            const distributionSelectAllButton = document.querySelector('[data-distribution-channel-select-all]');
            const distributionClearButton = document.querySelector('[data-distribution-channel-clear]');
            const knowledgeBaseInputs = document.querySelectorAll('[data-knowledge-base-input]');
            const knowledgeBaseCount = document.querySelector('[data-knowledge-base-count]');
            const knowledgeBaseToggle = document.querySelector('[data-knowledge-base-toggle]');
            const collapsedKnowledgeBaseCards = document.querySelectorAll('[data-knowledge-base-collapsed="true"]');
            const form = document.querySelector('form');
            let distributionChannelsExpanded = false;
            let knowledgeBaseExpanded = false;

            if (!form) {
                return;
            }

            function toggleImageCountByLibrary() {
                if (!imageLibrarySelect.value) {
                    imageCountSelect.value = '0';
                    imageCountSelect.disabled = true;
                } else {
                    imageCountSelect.disabled = false;
                    if (imageCountSelect.value === '0') {
                        imageCountSelect.value = '1';
                    }
                }
            }

            function togglePublishInterval() {
                if (needReviewCheckbox.checked) {
                    publishIntervalInput.disabled = true;
                    publishIntervalInput.parentElement.style.opacity = '0.5';
                } else {
                    publishIntervalInput.disabled = false;
                    publishIntervalInput.parentElement.style.opacity = '1';
                }
            }

            function handleCategoryModeChange() {
                const selected = document.querySelector('input[name="category_mode"]:checked');
                if (!selected) {
                    return;
                }

                if (selected.value === 'fixed') {
                    fixedCategorySection.classList.remove('hidden');
                    fixedCategorySelect.required = true;
                } else {
                    fixedCategorySection.classList.add('hidden');
                    fixedCategorySelect.required = false;
                    fixedCategorySelect.value = '';
                }
            }

            function syncDraftLimitMax() {
                const articleLimit = Math.max(1, Number(articleLimitInput.value || 1));
                draftLimitInput.max = String(articleLimit);
                if (Number(draftLimitInput.value || 1) > articleLimit) {
                    draftLimitInput.value = String(articleLimit);
                }
            }

            function syncDistributionChannelsByScope() {
                const selectedScope = document.querySelector('input[name="publish_scope"]:checked');
                const isLocalOnly = selectedScope && selectedScope.value === 'local_only';

                distributionStrategyInputs.forEach((input) => {
                    input.disabled = isLocalOnly;
                });

                distributionStrategyCards.forEach((card) => {
                    card.classList.toggle('cursor-pointer', !isLocalOnly);
                    card.classList.toggle('hover:border-blue-300', !isLocalOnly);
                    card.classList.toggle('hover:bg-blue-50', !isLocalOnly);
                    card.classList.toggle('cursor-not-allowed', isLocalOnly);
                    card.classList.toggle('bg-gray-50', isLocalOnly);
                    card.classList.toggle('opacity-50', isLocalOnly);
                });

                distributionChannelInputs.forEach((input) => {
                    input.disabled = isLocalOnly;
                    if (isLocalOnly) {
                        input.checked = false;
                    }

                    const card = input.closest('[data-distribution-channel-card]');
                    if (!card) {
                        return;
                    }

                    card.classList.toggle('cursor-pointer', !isLocalOnly);
                    card.classList.toggle('hover:border-blue-300', !isLocalOnly);
                    card.classList.toggle('hover:bg-blue-50', !isLocalOnly);
                    card.classList.toggle('cursor-not-allowed', isLocalOnly);
                    card.classList.toggle('bg-gray-50', isLocalOnly);
                    card.classList.toggle('opacity-50', isLocalOnly);
                });

                [distributionSelectAllButton, distributionClearButton].forEach((button) => {
                    if (button) {
                        button.disabled = isLocalOnly;
                    }
                });

                syncDistributionChannelCount();
                syncDistributionChannelVisibility();
            }

            function syncDistributionChannelCount() {
                if (!distributionChannelCount) {
                    return;
                }

                const selectedCount = Array.from(distributionChannelInputs).filter((input) => input.checked).length;
                distributionChannelCount.textContent = @json($t('task_create.label.distribution_channel_selected_count', ['count' => '__COUNT__'])).replace('__COUNT__', String(selectedCount));
            }

            function syncDistributionChannelVisibility() {
                if (!distributionChannelToggle || collapsedDistributionChannelCards.length === 0) {
                    return;
                }

                const hiddenCards = [];

                collapsedDistributionChannelCards.forEach((card) => {
                    const input = card.querySelector('[data-distribution-channel-input]');
                    const shouldHide = !distributionChannelsExpanded && !(input && input.checked);
                    card.classList.toggle('hidden', shouldHide);

                    if (shouldHide) {
                        hiddenCards.push(card);
                    }
                });

                const expandLabel = distributionChannelToggle.dataset.expandLabel || '';
                const collapseLabel = distributionChannelToggle.dataset.collapseLabel || '';
                distributionChannelToggle.textContent = distributionChannelsExpanded
                    ? collapseLabel
                    : expandLabel.replace('__COUNT__', String(hiddenCards.length));
                distributionChannelToggle.setAttribute('aria-expanded', distributionChannelsExpanded ? 'true' : 'false');
                distributionChannelToggle.classList.toggle('hidden', !distributionChannelsExpanded && hiddenCards.length === 0);
            }

            function syncKnowledgeBaseCount() {
                if (!knowledgeBaseCount) {
                    return;
                }

                const selectedCount = Array.from(knowledgeBaseInputs).filter((input) => input.checked).length;
                knowledgeBaseCount.textContent = @json($t('task_create.label.knowledge_base_selected_count', ['count' => '__COUNT__', 'max' => 5])).replace('__COUNT__', String(selectedCount));
            }

            function syncKnowledgeBaseVisibility() {
                if (!knowledgeBaseToggle || collapsedKnowledgeBaseCards.length === 0) {
                    return;
                }

                const hiddenCards = [];

                collapsedKnowledgeBaseCards.forEach((card) => {
                    const input = card.querySelector('[data-knowledge-base-input]');
                    const shouldHide = !knowledgeBaseExpanded && !(input && input.checked);
                    card.classList.toggle('hidden', shouldHide);

                    if (shouldHide) {
                        hiddenCards.push(card);
                    }
                });

                const expandLabel = knowledgeBaseToggle.dataset.expandLabel || '';
                const collapseLabel = knowledgeBaseToggle.dataset.collapseLabel || '';
                knowledgeBaseToggle.textContent = knowledgeBaseExpanded
                    ? collapseLabel
                    : expandLabel.replace('__COUNT__', String(hiddenCards.length));
                knowledgeBaseToggle.setAttribute('aria-expanded', knowledgeBaseExpanded ? 'true' : 'false');
                knowledgeBaseToggle.classList.toggle('hidden', !knowledgeBaseExpanded && hiddenCards.length === 0);
            }

            imageLibrarySelect.addEventListener('change', toggleImageCountByLibrary);
            needReviewCheckbox.addEventListener('change', togglePublishInterval);
            articleLimitInput.addEventListener('input', syncDraftLimitMax);
            categoryModeRadios.forEach((radio) => radio.addEventListener('change', handleCategoryModeChange));
            publishScopeRadios.forEach((radio) => radio.addEventListener('change', syncDistributionChannelsByScope));
            distributionChannelInputs.forEach((input) => {
                input.addEventListener('change', function () {
                    syncDistributionChannelCount();
                    syncDistributionChannelVisibility();
                });
            });
            if (distributionSelectAllButton) {
                distributionSelectAllButton.addEventListener('click', function () {
                    distributionChannelInputs.forEach((input) => {
                        if (!input.disabled) {
                            input.checked = true;
                        }
                    });
                    syncDistributionChannelCount();
                    syncDistributionChannelVisibility();
                });
            }
            if (distributionClearButton) {
                distributionClearButton.addEventListener('click', function () {
                    distributionChannelInputs.forEach((input) => {
                        if (!input.disabled) {
                            input.checked = false;
                        }
                    });
                    syncDistributionChannelCount();
                    syncDistributionChannelVisibility();
                });
            }
            if (distributionChannelToggle) {
                distributionChannelToggle.addEventListener('click', function () {
                    distributionChannelsExpanded = !distributionChannelsExpanded;
                    syncDistributionChannelVisibility();
                });
            }
            if (knowledgeBaseToggle) {
                knowledgeBaseToggle.addEventListener('click', function () {
                    knowledgeBaseExpanded = !knowledgeBaseExpanded;
                    syncKnowledgeBaseVisibility();
                });
            }
            knowledgeBaseInputs.forEach((input) => {
                input.addEventListener('change', function () {
                    const selectedCount = Array.from(knowledgeBaseInputs).filter((item) => item.checked).length;
                    if (selectedCount > 5) {
                        input.checked = false;
                        alert(@json($t('task_create.error.knowledge_base_limit')));
                    }

                    syncKnowledgeBaseCount();
                    syncKnowledgeBaseVisibility();
                });
            });

            form.addEventListener('submit', function (event) {
                if (!document.getElementById('task_name').value.trim()) {
                    alert(@json(__('admin.task_create.error.name_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('title_library_id').value) {
                    alert(@json(__('admin.task_create.error.title_library_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('prompt_id').value) {
                    alert(@json(__('admin.task_create.error.prompt_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('ai_model_id').value) {
                    alert(@json(__('admin.task_create.error.ai_model_required')));
                    event.preventDefault();
                    return;
                }

                if (Number(draftLimitInput.value || 0) > Number(articleLimitInput.value || 0)) {
                    alert(@json(__('admin.task_create.error.draft_limit_too_large')));
                    event.preventDefault();
                    return;
                }

                if (!isEditMode && !confirm(@json(__('admin.task_create.confirm.create')))) {
                    event.preventDefault();
                }
            });

            toggleImageCountByLibrary();
            togglePublishInterval();
            handleCategoryModeChange();
            syncDraftLimitMax();
            syncDistributionChannelsByScope();
            syncDistributionChannelCount();
            syncDistributionChannelVisibility();
            syncKnowledgeBaseCount();
            syncKnowledgeBaseVisibility();
        });
    </script>
@endpush
