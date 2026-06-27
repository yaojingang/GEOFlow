@extends('admin.layouts.app')

@php
    $isTrashView = (bool) ($isTrashView ?? false);
    $selectedTaskId = (int) ($filters['task_id'] ?? 0);
    $selectedStatus = (string) ($filters['status'] ?? '');
    $selectedReviewStatus = (string) ($filters['review_status'] ?? '');
    $selectedAuthorId = (int) ($filters['author_id'] ?? 0);
    $selectedDistributionChannelIds = collect($filters['distribution_channel_ids'] ?? [])
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->values()
        ->all();
    $selectedDateFrom = (string) ($filters['date_from'] ?? '');
    $selectedDateTo = (string) ($filters['date_to'] ?? '');
    $selectedSearch = (string) ($filters['search'] ?? '');
    $selectedPerPage = (int) ($filters['per_page'] ?? 20);
    $selectedTaskName = '';
    foreach ($tasks as $taskOption) {
        if ((int) ($taskOption['id'] ?? 0) === $selectedTaskId) {
            $selectedTaskName = (string) ($taskOption['name'] ?? '');
            break;
        }
    }
    $categoryManageUrl = route('admin.categories.index');
    $reviewCenterUrl = route('admin.articles.index', ['review_status' => 'pending']);
    $trashUrl = route('admin.articles.index', ['trashed' => 1]);
    $articlesIndexUrl = route('admin.articles.index');
    $clearTaskFilterUrl = route('admin.articles.index', request()->except(['task_id', 'page']));
    $contentWorkbenchItems = [
        [
            'icon' => 'shield-check',
            'title' => __('admin.articles.workbench.review_title'),
            'desc' => __('admin.articles.workbench.review_desc'),
            'count' => (int) ($stats['pending_review'] ?? 0),
            'href' => $reviewCenterUrl,
            'iconClass' => 'bg-amber-50 text-amber-600 ring-amber-100',
            'countClass' => 'text-amber-700',
            'linkClass' => 'text-amber-700 group-hover:text-amber-800',
        ],
        [
            'icon' => 'edit',
            'title' => __('admin.articles.workbench.optimize_title'),
            'desc' => __('admin.articles.workbench.optimize_desc'),
            'count' => (int) ($stats['draft'] ?? 0),
            'href' => route('admin.articles.index', ['status' => 'draft']),
            'iconClass' => 'bg-blue-50 text-blue-600 ring-blue-100',
            'countClass' => 'text-blue-700',
            'linkClass' => 'text-blue-700 group-hover:text-blue-800',
        ],
        [
            'icon' => 'send',
            'title' => __('admin.articles.workbench.distribution_title'),
            'desc' => __('admin.articles.workbench.distribution_desc'),
            'count' => (int) ($stats['published'] ?? 0),
            'href' => route('admin.articles.index', ['status' => 'published']),
            'iconClass' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
            'countClass' => 'text-emerald-700',
            'linkClass' => 'text-emerald-700 group-hover:text-emerald-800',
        ],
        [
            'icon' => 'chart-no-axes-combined',
            'title' => __('admin.articles.workbench.observation_title'),
            'desc' => __('admin.articles.workbench.observation_desc'),
            'count' => (int) ($stats['observed'] ?? 0),
            'href' => route('admin.analytics'),
            'iconClass' => 'bg-purple-50 text-purple-600 ring-purple-100',
            'countClass' => 'text-purple-700',
            'linkClass' => 'text-purple-700 group-hover:text-purple-800',
        ],
    ];
    $workbenchPriority = collect($contentWorkbenchItems)
        ->sortByDesc(fn ($item) => (int) ($item['count'] ?? 0))
        ->first();
    $workbenchHasPriority = (int) ($workbenchPriority['count'] ?? 0) > 0;
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $isTrashView ? __('admin.articles.trash.subtitle') : __('admin.articles.page_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2 justify-end">
                @if($isTrashView)
                    <a href="{{ $articlesIndexUrl }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.articles.trash.back') }}
                    </a>
                    <button type="button" onclick="submitEmptyTrash()" class="inline-flex items-center px-4 py-2 border border-red-200 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50">
                        <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.articles.trash.empty') }}
                    </button>
                @else
                    <a href="{{ route('admin.articles.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.create_article') }}
                    </a>
                    <a href="{{ $categoryManageUrl }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="folder" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.category_manage') }}
                    </a>
                    <a href="{{ $reviewCenterUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.button.review_center') }}
                    </a>
                @endif
                <a href="{{ $isTrashView ? $articlesIndexUrl : $trashUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                    {{ $isTrashView ? __('admin.articles.page_title') : __('admin.button.trash') }}
                </a>
                <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                    {{ __('admin.button.bulk_actions') }}
                </button>
            </div>
        </div>

        @if($isTrashView)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg md:col-span-1">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="archive" class="h-6 w-6 text-orange-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.trash.stats_total') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['trashed_total'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @else
        <section class="mb-8">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">{{ __('admin.articles.workbench.eyebrow') }}</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ __('admin.articles.workbench.title') }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ __('admin.articles.workbench.desc') }}</p>
                </div>
                <a href="{{ route('admin.tasks.create') }}" class="inline-flex w-fit items-center rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                    <i data-lucide="workflow" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.articles.workbench.create_task') }}
                </a>
            </div>
            <div class="mb-4 rounded-lg border border-blue-100 bg-white p-4 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md ring-1 {{ $workbenchHasPriority ? $workbenchPriority['iconClass'] : 'bg-blue-50 text-blue-600 ring-blue-100' }}">
                            <i data-lucide="{{ $workbenchHasPriority ? $workbenchPriority['icon'] : 'circle-check' }}" class="h-5 w-5"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">{{ __('admin.articles.workbench.current_action_title') }}</p>
                            <h3 class="mt-1 text-base font-semibold text-gray-900">
                                @if($workbenchHasPriority)
                                    {{ __('admin.articles.workbench.current_action_desc', ['count' => (int) $workbenchPriority['count'], 'stage' => $workbenchPriority['title']]) }}
                                @else
                                    {{ __('admin.articles.workbench.current_action_empty_title') }}
                                @endif
                            </h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">
                                {{ $workbenchHasPriority ? __('admin.articles.workbench.current_action_help') : __('admin.articles.workbench.current_action_empty_desc') }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ $workbenchHasPriority ? $workbenchPriority['href'] : route('admin.tasks.create') }}" class="inline-flex h-9 w-fit shrink-0 items-center rounded-md bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700">
                        {{ $workbenchHasPriority ? __('admin.articles.workbench.current_action_button') : __('admin.articles.workbench.current_action_empty_button') }}
                        <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4"></i>
                    </a>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($contentWorkbenchItems as $item)
                    <a href="{{ $item['href'] }}" class="group flex min-h-44 flex-col justify-between rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md">
                        <div>
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-md ring-1 {{ $item['iconClass'] }}">
                                    <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5"></i>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl font-semibold {{ $item['countClass'] }}">{{ $item['count'] }}</div>
                                </div>
                            </div>
                            <h3 class="mt-5 text-base font-semibold text-gray-900">{{ $item['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ $item['desc'] }}</p>
                        </div>
                        <div class="mt-5 inline-flex items-center text-sm font-semibold {{ $item['linkClass'] }}">
                            {{ __('admin.articles.workbench.open') }}
                            <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4 transition group-hover:translate-x-0.5"></i>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="file-text" class="h-6 w-6 text-blue-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.total') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['total'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="globe" class="h-6 w-6 text-green-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.published') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['published'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="edit" class="h-6 w-6 text-yellow-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.draft') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['draft'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="eye" class="h-6 w-6 text-purple-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.pending_review') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['pending_review'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="calendar" class="h-6 w-6 text-orange-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.today') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['today'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.articles.filters.title') }}</h3>
            </div>
            <div class="px-6 py-4">
                @if($selectedTaskId > 0)
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        <div class="inline-flex items-center gap-2">
                            <i data-lucide="filter" class="h-4 w-4"></i>
                            <span>{{ __('admin.articles.filters.current_task', ['task' => $selectedTaskName !== '' ? $selectedTaskName : '#'.$selectedTaskId]) }}</span>
                        </div>
                        <a href="{{ $clearTaskFilterUrl }}" class="inline-flex items-center font-medium text-blue-700 hover:text-blue-900">
                            <i data-lucide="x" class="mr-1 h-4 w-4"></i>
                            {{ __('admin.articles.filters.clear_task') }}
                        </a>
                    </div>
                @endif
                <form method="GET" class="space-y-4">
                    @if($isTrashView)
                        <input type="hidden" name="trashed" value="1">
                    @endif
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.task') }}</label>
                            <select name="task_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_tasks') }}</option>
                                @foreach($tasks as $task)
                                    <option value="{{ (int) $task['id'] }}" @selected($selectedTaskId === (int) $task['id'])>{{ $task['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if(!$isTrashView)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.status') }}</label>
                            <select name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_status') }}</option>
                                <option value="draft" @selected($selectedStatus === 'draft')>{{ __('admin.articles.status.draft') }}</option>
                                <option value="published" @selected($selectedStatus === 'published')>{{ __('admin.articles.status.published') }}</option>
                                <option value="private" @selected($selectedStatus === 'private')>{{ __('admin.articles.status.private') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.review_status') }}</label>
                            <select name="review_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_review') }}</option>
                                <option value="pending" @selected($selectedReviewStatus === 'pending')>{{ __('admin.articles.review.pending') }}</option>
                                <option value="approved" @selected($selectedReviewStatus === 'approved')>{{ __('admin.articles.review.approved') }}</option>
                                <option value="rejected" @selected($selectedReviewStatus === 'rejected')>{{ __('admin.articles.review.rejected') }}</option>
                                <option value="auto_approved" @selected($selectedReviewStatus === 'auto_approved')>{{ __('admin.articles.review.auto_approved') }}</option>
                            </select>
                        </div>
                        @endif
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.author') }}</label>
                            <select name="author_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_authors') }}</option>
                                @foreach($authors as $author)
                                    <option value="{{ (int) $author['id'] }}" @selected($selectedAuthorId === (int) $author['id'])>{{ $author['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.date_from') }}</label>
                            <input type="date" name="date_from" value="{{ $selectedDateFrom }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.date_to') }}</label>
                            <input type="date" name="date_to" value="{{ $selectedDateTo }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>
                    @if(!empty($distributionChannels))
                        <div>
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.distribution_channel') }}</label>
                                <div class="flex items-center gap-2">
                                    <span data-distribution-channel-filter-count class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">
                                        {{ __('admin.articles.filters.distribution_channel_selected_count', ['count' => count($selectedDistributionChannelIds)]) }}
                                    </span>
                                    <button type="button"
                                            data-distribution-channel-filter-toggle
                                            aria-expanded="false"
                                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                        <span data-distribution-channel-filter-toggle-label>{{ __('admin.articles.filters.distribution_channel_expand') }}</span>
                                        <i data-lucide="chevron-down" data-distribution-channel-filter-toggle-icon class="ml-1 h-3.5 w-3.5 transition-transform"></i>
                                    </button>
                                </div>
                            </div>
                            <div data-distribution-channel-filter-panel class="hidden grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach($distributionChannels as $channel)
                                    <label data-distribution-channel-filter-card @class([
                                        'flex items-start gap-3 rounded-md border px-4 py-3 text-sm transition',
                                        'border-blue-200 bg-blue-50' => in_array((int) ($channel['id'] ?? 0), $selectedDistributionChannelIds, true),
                                        'border-gray-200 bg-white hover:border-blue-300 hover:bg-blue-50' => ! in_array((int) ($channel['id'] ?? 0), $selectedDistributionChannelIds, true),
                                    ])>
                                        <input type="checkbox"
                                               name="distribution_channel_ids[]"
                                               value="{{ (int) ($channel['id'] ?? 0) }}"
                                               @checked(in_array((int) ($channel['id'] ?? 0), $selectedDistributionChannelIds, true))
                                               data-distribution-channel-filter-input
                                               class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="min-w-0">
                                            <span class="block font-medium text-gray-900">{{ $channel['name'] }}</span>
                                            @if((string) ($channel['domain'] ?? '') !== '')
                                                <span class="block break-all text-gray-500">{{ (string) ($channel['domain'] ?? '') }}</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ __('admin.articles.filters.distribution_channel_help') }}</p>
                        </div>
                    @endif
                    <div class="flex items-end space-x-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.search') }}</label>
                            <input type="text" name="search" value="{{ $selectedSearch }}" placeholder="{{ __('admin.articles.filters.search_placeholder') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div class="flex space-x-2">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.button.search') }}
                            </button>
                            <a href="{{ $isTrashView ? route('admin.articles.index', ['trashed' => 1]) : route('admin.articles.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.button.clear') }}
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ $isTrashView ? __('admin.articles.trash.list_title') : __('admin.articles.list_title') }}
                        <span class="text-sm text-gray-500">{{ __('admin.articles.list_total', ['count' => $articles->total()]) }}</span>
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        @if(!$isTrashView)
                        <a href="{{ route('admin.articles.create') }}" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.create_article') }}
                        </a>
                        <a href="{{ $reviewCenterUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.review_center') }}
                        </a>
                        @endif
                        <a href="{{ $isTrashView ? $articlesIndexUrl : $trashUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                            {{ $isTrashView ? __('admin.articles.page_title') : __('admin.button.trash') }}
                        </a>
                        <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.bulk_actions') }}
                        </button>
                    </div>
                </div>
            </div>

            @if($articles->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $isTrashView ? __('admin.articles.trash.empty_title') : __('admin.articles.empty_title') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $isTrashView ? __('admin.articles.trash.empty_desc') : __('admin.articles.empty_desc') }}</p>
                    @if($isTrashView)
                        <a href="{{ $articlesIndexUrl }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.articles.trash.back') }}
                        </a>
                    @else
                        <a href="{{ route('admin.tasks.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.generate_articles') }}
                        </a>
                    @endif
                </div>
            @else
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" action="{{ \App\Support\AdminWeb::routePath('admin.articles.batch.update-status') }}" id="batch-form">
                        @csrf
                        <div id="batch-selected-ids"></div>
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600">
                                @if(__('admin.articles.bulk.selected_prefix') !== '')
                                    <span>{{ __('admin.articles.bulk.selected_prefix') }}</span>
                                @endif
                                <span id="selected-count">0</span>
                                <span>{{ __('admin.articles.bulk.selected_suffix') }}</span>
                            </span>
                            <select name="action" id="batch-action" class="border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">{{ __('admin.articles.bulk.select_action') }}</option>
                                @if($isTrashView)
                                    <option value="batch_restore">{{ __('admin.articles.trash.action_restore') }}</option>
                                    <option value="batch_force_delete">{{ __('admin.articles.trash.action_force_delete') }}</option>
                                @else
                                    <option value="batch_update_status">{{ __('admin.articles.bulk.status_to') }}</option>
                                    <option value="batch_update_review">{{ __('admin.articles.bulk.review_to') }}</option>
                                    <option value="delete_articles">{{ __('admin.articles.bulk.delete') }}</option>
                                @endif
                            </select>
                            @if(!$isTrashView)
                            <select name="new_status" id="status-select" class="hidden border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="draft">{{ __('admin.articles.status.draft') }}</option>
                                <option value="published">{{ __('admin.articles.status.published') }}</option>
                                <option value="private">{{ __('admin.articles.status.private') }}</option>
                            </select>
                            <select name="review_status" id="review-select" class="hidden border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="pending">{{ __('admin.articles.review.pending') }}</option>
                                <option value="approved">{{ __('admin.articles.review.approved') }}</option>
                                <option value="rejected">{{ __('admin.articles.review.rejected') }}</option>
                                <option value="auto_approved">{{ __('admin.articles.review.auto_approved') }}</option>
                            </select>
                            @endif
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                {{ __('admin.button.execute') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="batch-checkbox hidden px-6 py-3 text-left">
                                <input type="checkbox" id="select-all" class="rounded border-gray-300 text-blue-600 shadow-sm">
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.id') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.info') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.task_author') }}</th>
                            @if(!$isTrashView)
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.workflow') }}</th>
                            @endif
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $isTrashView ? __('admin.articles.trash.column.deleted_at') : __('admin.articles.column.created_at') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($articles as $article)
                            @php
                                $statusClass = match((string) $article->status) {
                                    'published' => 'bg-green-100 text-green-800 border border-green-200',
                                    'draft' => 'bg-amber-100 text-amber-800 border border-amber-200',
                                    default => 'bg-gray-100 text-gray-700 border border-gray-200'
                                };
                                $reviewClass = match((string) $article->review_status) {
                                    'approved' => 'bg-emerald-100 text-emerald-800 border border-emerald-200',
                                    'auto_approved' => 'bg-sky-100 text-sky-800 border border-sky-200',
                                    'rejected' => 'bg-red-100 text-red-800 border border-red-200',
                                    default => 'bg-yellow-100 text-yellow-800 border border-yellow-200'
                                };
                                $distributionTotal = (int) ($article->distribution_total_count ?? 0);
                                $distributionSynced = (int) ($article->distribution_synced_count ?? 0);
                                $distributionFailed = (int) ($article->distribution_failed_count ?? 0);
                                $distributionPending = max(0, $distributionTotal - $distributionSynced - $distributionFailed);
                                $articleDistributionChannels = collect($article->distributions ?? []);
                                if (count($selectedDistributionChannelIds) > 0) {
                                    $articleDistributionChannels = $articleDistributionChannels->filter(
                                        fn ($distribution): bool => in_array((int) ($distribution->distribution_channel_id ?? 0), $selectedDistributionChannelIds, true)
                                    );
                                }
                                $articleDistributionChannelLabels = $articleDistributionChannels
                                    ->map(function ($distribution): string {
                                        $channelName = (string) ($distribution->channel->name ?? '');
                                        $channelDomain = (string) ($distribution->channel->domain ?? '');
                                        if ($channelName !== '' && $channelDomain !== '') {
                                            return $channelName.' · '.$channelDomain;
                                        }

                                        return $channelName !== '' ? $channelName : $channelDomain;
                                    })
                                    ->filter(fn (string $label): bool => $label !== '')
                                    ->unique()
                                    ->values();
                                $remoteDistributions = collect($article->syncedRemoteDistributions ?? []);
                                if (count($selectedDistributionChannelIds) > 0) {
                                    $remoteDistributions = $remoteDistributions->filter(
                                        fn ($distribution): bool => in_array((int) ($distribution->distribution_channel_id ?? 0), $selectedDistributionChannelIds, true)
                                    );
                                }
                                $remoteViewLinks = $remoteDistributions
                                    ->map(function ($distribution): array {
                                        $remoteUrl = trim((string) ($distribution->remote_url ?? ''));
                                        $channelName = (string) ($distribution->channel->name ?? '');
                                        $channelDomain = (string) ($distribution->channel->domain ?? '');

                                        return [
                                            'url' => $remoteUrl,
                                            'channel' => $channelName !== '' ? $channelName : ($channelDomain !== '' ? $channelDomain : __('admin.articles.action.remote_channel_unknown')),
                                            'host' => (string) (parse_url($remoteUrl, PHP_URL_HOST) ?: $channelDomain),
                                        ];
                                    })
                                    ->filter(function (array $link): bool {
                                        $url = (string) ($link['url'] ?? '');
                                        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                                        return filter_var($url, FILTER_VALIDATE_URL) !== false
                                            && in_array($scheme, ['http', 'https'], true);
                                    })
                                    ->values();
                                $primaryRemoteLink = $remoteViewLinks->first();
                                $localArticleUrl = null;
                                if ((string) $article->status === 'published' && trim((string) $article->slug) !== '') {
                                    $localArticleUrl = route('site.article', ['slug' => (string) $article->slug]);
                                }
                                $primaryPublishedLink = $primaryRemoteLink;
                                if ($primaryPublishedLink === null && $localArticleUrl !== null) {
                                    $primaryPublishedLink = [
                                        'url' => $localArticleUrl,
                                        'channel' => __('admin.articles.action.local_site'),
                                        'title' => __('admin.articles.action.view_local'),
                                    ];
                                } elseif ($primaryPublishedLink !== null) {
                                    $primaryPublishedLink['title'] = __('admin.articles.action.view_remote_for_channel', ['channel' => $primaryPublishedLink['channel']]);
                                }
                                $distributionBadge = null;
                                if (!$isTrashView && $distributionTotal > 0) {
                                    if ($distributionFailed > 0) {
                                        $distributionBadge = [
                                            'label' => __('admin.distribution.article_status.failed'),
                                            'detail' => $distributionFailed.'/'.$distributionTotal,
                                            'class' => 'bg-red-50 text-red-700 ring-red-100',
                                        ];
                                    } elseif ($distributionSynced >= $distributionTotal) {
                                        $distributionBadge = [
                                            'label' => __('admin.distribution.article_status.synced'),
                                            'detail' => $distributionSynced.'/'.$distributionTotal,
                                            'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                                        ];
                                    } else {
                                        $distributionBadge = [
                                            'label' => __('admin.distribution.article_status.queued'),
                                            'detail' => $distributionPending.'/'.$distributionTotal,
                                            'class' => 'bg-sky-50 text-sky-700 ring-sky-100',
                                        ];
                                    }
                                }
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="batch-checkbox hidden px-6 py-4">
                                    <input type="checkbox" value="{{ (int) $article->id }}" class="article-checkbox rounded border-gray-300 text-blue-600 shadow-sm">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">#{{ (int) $article->id }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900 truncate">
                                        @if($isTrashView)
                                            <span>{{ $article->title }}</span>
                                        @else
                                            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $article->id]) }}" class="hover:text-blue-600">{{ $article->title }}</a>
                                        @endif
                                    </div>
                                    @if((string) ($article->excerpt ?? '') !== '')
                                        <p class="text-xs text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit((string) $article->excerpt, 100) }}</p>
                                    @endif
                                    @if((string) ($article->keywords ?? '') !== '')
                                        <div class="text-xs text-blue-600 mt-1">{{ __('admin.articles.keywords') }}: {{ $article->keywords }}</div>
                                    @endif
                                    @if(!$isTrashView && (!empty($article->is_hot) || !empty($article->is_featured)))
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @if(!empty($article->is_hot))
                                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-100">{{ __('admin.articles.badge.hot') }}</span>
                                            @endif
                                            @if(!empty($article->is_featured))
                                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-blue-100">{{ __('admin.articles.badge.featured') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if($distributionBadge !== null)
                                        <div class="mt-2">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $distributionBadge['class'] }}">
                                                <i data-lucide="send" class="mr-1 h-3 w-3"></i>
                                                {{ $distributionBadge['label'] }}
                                                <span class="ml-1 font-mono text-[11px] opacity-80">{{ $distributionBadge['detail'] }}</span>
                                            </span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div class="space-y-1.5">
                                        @if((string) ($article->task->name ?? '') !== '')
                                            <div class="max-w-[220px] truncate font-medium text-blue-600" title="{{ $article->task->name }}">{{ $article->task->name }}</div>
                                        @endif
                                        <div class="text-gray-600">{{ $article->author->name ?? '' }}</div>
                                        @if($articleDistributionChannelLabels->isNotEmpty())
                                            <div class="flex max-w-[240px] flex-wrap gap-1.5">
                                                @foreach($articleDistributionChannelLabels->take(3) as $channelLabel)
                                                    <span class="inline-flex max-w-full items-center rounded-full bg-slate-50 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200" title="{{ $channelLabel }}">
                                                        <i data-lucide="radio-tower" class="mr-1 h-3 w-3 shrink-0"></i>
                                                        <span class="max-w-[170px] truncate">{{ $channelLabel }}</span>
                                                    </span>
                                                @endforeach
                                                @if($articleDistributionChannelLabels->count() > 3)
                                                    <span class="inline-flex items-center rounded-full bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-500 ring-1 ring-gray-200">
                                                        +{{ $articleDistributionChannelLabels->count() - 3 }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                        @if((int) ($article->is_ai_generated ?? 0) === 1)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">{{ __('admin.articles.ai_generated') }}</span>
                                        @endif
                                    </div>
                                </td>
                                @if(!$isTrashView)
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusClass }}">
                                            {{ __('admin.articles.publish_prefix') }}: {{ __('admin.articles.status.'.(string) $article->status) }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $reviewClass }}">
                                            {{ __('admin.articles.review_prefix') }}: {{ __('admin.articles.review.'.(string) $article->review_status) }}
                                        </span>
                                    </div>
                                </td>
                                @endif
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if($isTrashView)
                                        <div>{{ optional($article->deleted_at)->format('Y-m-d H:i') }}</div>
                                        <div class="text-xs text-gray-400">{{ __('admin.articles.trash.created_prefix') }} {{ optional($article->created_at)->format('m-d H:i') }}</div>
                                    @else
                                        <div>{{ optional($article->created_at)->format('m-d H:i') }}</div>
                                        @if($article->published_at)
                                            <div class="text-xs text-green-600">{{ __('admin.articles.published_at', ['time' => $article->published_at->format('m-d H:i')]) }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    @if($isTrashView)
                                        <div class="flex items-center space-x-2">
                                            <form method="POST" action="{{ route('admin.articles.restore', ['articleId' => (int) $article->id]) }}" class="inline" onsubmit="return confirm(@json(__('admin.articles.trash.confirm_restore')))">
                                                @csrf
                                                <button type="submit" class="text-green-600 hover:text-green-800" title="{{ __('admin.articles.trash.action_restore') }}">
                                                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.articles.force-delete', ['articleId' => (int) $article->id]) }}" class="inline" onsubmit="return confirm(@json(__('admin.articles.trash.confirm_delete')))">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-800" title="{{ __('admin.articles.trash.action_force_delete') }}">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <div class="flex items-center space-x-2">
                                            @if($primaryPublishedLink !== null)
                                                <a href="{{ $primaryPublishedLink['url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800" title="{{ $primaryPublishedLink['title'] }}">
                                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                                </a>
                                            @else
                                                <span class="inline-flex cursor-not-allowed text-gray-300" title="{{ __('admin.articles.action.view_remote_unavailable') }}">
                                                    <i data-lucide="eye-off" class="w-4 h-4"></i>
                                                </span>
                                            @endif
                                            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $article->id]) }}" class="text-green-600 hover:text-green-800" title="{{ __('admin.button.edit') }}">
                                                <i data-lucide="edit" class="w-4 h-4"></i>
                                            </a>
                                            @if((string) $article->review_status === 'pending')
                                                <button type="button" onclick="quickReview({{ (int) $article->id }}, 'approved')" class="text-green-600 hover:text-green-800" title="{{ __('admin.articles.action.approve') }}">
                                                    <i data-lucide="check" class="w-4 h-4"></i>
                                                </button>
                                                <button type="button" onclick="quickReview({{ (int) $article->id }}, 'rejected')" class="text-red-600 hover:text-red-800" title="{{ __('admin.articles.action.reject') }}">
                                                    <i data-lucide="x" class="w-4 h-4"></i>
                                                </button>
                                            @endif
                                            <button type="button" onclick="deleteArticle({{ (int) $article->id }})" class="text-red-600 hover:text-red-800" title="{{ __('admin.button.delete') }}">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="text-sm text-gray-700">
                            {{ __('admin.articles.pagination.summary', ['from' => $articles->firstItem() ?? 0, 'to' => $articles->lastItem() ?? 0, 'total' => $articles->total()]) }}
                            @if($articles->lastPage() > 1)
                                <span class="ml-2 text-gray-500">{{ __('admin.articles.pagination.pages', ['page' => $articles->currentPage(), 'total_pages' => $articles->lastPage()]) }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <form method="GET" class="flex items-center gap-2">
                                @foreach(request()->except(['per_page', 'page']) as $key => $value)
                                    @if(is_array($value))
                                        @foreach($value as $arrayValue)
                                            <input type="hidden" name="{{ $key }}[]" value="{{ $arrayValue }}">
                                        @endforeach
                                    @else
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endforeach
                                <input type="hidden" name="page" value="1">
                                <label for="per-page-input" class="text-sm text-gray-600">{{ __('admin.articles.pagination.per_page') }}</label>
                                <input id="per-page-input" type="number" name="per_page" min="10" max="100" step="1" value="{{ $selectedPerPage }}" class="w-20 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 shadow-sm">
                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.apply') }}</button>
                            </form>
                        </div>
                    </div>
                    <div class="mt-4">
                        {{ $articles->onEachSide(1)->links() }}
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const ARTICLES_I18N = @json($articlesI18n);
        const TRASH_I18N = @json($trashI18n);
        const IS_TRASH_VIEW = @json($isTrashView);
        const EMPTY_TRASH_URL = @json(\App\Support\AdminWeb::routePath('admin.articles.trash.empty'));
        const DISTRIBUTION_CHANNEL_FILTER_COUNT_LABEL = @json(__('admin.articles.filters.distribution_channel_selected_count', ['count' => '__COUNT__']));
        const DISTRIBUTION_CHANNEL_FILTER_EXPAND_LABEL = @json(__('admin.articles.filters.distribution_channel_expand'));
        const DISTRIBUTION_CHANNEL_FILTER_COLLAPSE_LABEL = @json(__('admin.articles.filters.distribution_channel_collapse'));

        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            if (!batchActions) {
                return;
            }

            const isHidden = batchActions.classList.contains('hidden');
            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach((node) => node.classList.remove('hidden'));
                return;
            }

            batchActions.classList.add('hidden');
            checkboxes.forEach((node) => node.classList.add('hidden'));
            document.querySelectorAll('.article-checkbox').forEach((node) => node.checked = false);
            const selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.checked = false;
            }
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const countElement = document.getElementById('selected-count');
            if (!countElement) {
                return;
            }
            countElement.textContent = String(document.querySelectorAll('.article-checkbox:checked').length);
        }

        const ARTICLE_BATCH_ROUTES = @json($articleBatchRoutes);

        function submitEmptyTrash() {
            if (!confirm(TRASH_I18N.confirmEmpty)) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = EMPTY_TRASH_URL;
            form.style.display = 'none';
            form.innerHTML = `<input type="hidden" name="_token" value="{{ csrf_token() }}">`;
            document.body.appendChild(form);
            form.submit();
        }

        function submitAction(action, articleId, extra = {}) {
            const targetAction = ARTICLE_BATCH_ROUTES[action] ?? '';
            if (targetAction === '') {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = targetAction;
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" name="article_ids[]" value="${articleId}">
            `;
            Object.entries(extra).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = String(value);
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        }

        function deleteArticle(articleId) {
            if (!confirm(ARTICLES_I18N.confirmDelete)) {
                return;
            }
            submitAction('delete_articles', articleId);
        }

        function quickReview(articleId, status) {
            const actionText = status === 'approved' ? ARTICLES_I18N.reviewApproved : ARTICLES_I18N.reviewRejected;
            if (!confirm(ARTICLES_I18N.confirmQuickReview.replace('__ACTION__', actionText))) {
                return;
            }
            submitAction('batch_update_review', articleId, { review_status: status });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const distributionChannelFilterInputs = document.querySelectorAll('[data-distribution-channel-filter-input]');
            const distributionChannelFilterCount = document.querySelector('[data-distribution-channel-filter-count]');
            const distributionChannelFilterPanel = document.querySelector('[data-distribution-channel-filter-panel]');
            const distributionChannelFilterToggle = document.querySelector('[data-distribution-channel-filter-toggle]');
            const distributionChannelFilterToggleLabel = document.querySelector('[data-distribution-channel-filter-toggle-label]');
            const distributionChannelFilterToggleIcon = document.querySelector('[data-distribution-channel-filter-toggle-icon]');

            function setDistributionChannelFilterExpanded(isExpanded) {
                if (!distributionChannelFilterPanel || !distributionChannelFilterToggle) {
                    return;
                }

                distributionChannelFilterPanel.classList.toggle('hidden', !isExpanded);
                distributionChannelFilterToggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                if (distributionChannelFilterToggleLabel) {
                    distributionChannelFilterToggleLabel.textContent = isExpanded
                        ? DISTRIBUTION_CHANNEL_FILTER_COLLAPSE_LABEL
                        : DISTRIBUTION_CHANNEL_FILTER_EXPAND_LABEL;
                }
                distributionChannelFilterToggleIcon?.classList.toggle('rotate-180', isExpanded);
            }

            function syncDistributionChannelFilterState() {
                const selectedCount = Array.from(distributionChannelFilterInputs).filter((input) => input.checked).length;
                if (distributionChannelFilterCount) {
                    distributionChannelFilterCount.textContent = DISTRIBUTION_CHANNEL_FILTER_COUNT_LABEL.replace('__COUNT__', String(selectedCount));
                }

                distributionChannelFilterInputs.forEach((input) => {
                    const card = input.closest('[data-distribution-channel-filter-card]');
                    if (!card) {
                        return;
                    }

                    const isSelected = input.checked;
                    card.classList.toggle('border-blue-200', isSelected);
                    card.classList.toggle('bg-blue-50', isSelected);
                    card.classList.toggle('border-gray-200', !isSelected);
                    card.classList.toggle('bg-white', !isSelected);
                    card.classList.toggle('hover:border-blue-300', !isSelected);
                    card.classList.toggle('hover:bg-blue-50', !isSelected);
                });
            }

            distributionChannelFilterInputs.forEach((input) => {
                input.addEventListener('change', syncDistributionChannelFilterState);
            });
            distributionChannelFilterToggle?.addEventListener('click', function() {
                setDistributionChannelFilterExpanded(this.getAttribute('aria-expanded') !== 'true');
            });
            setDistributionChannelFilterExpanded(false);
            syncDistributionChannelFilterState();

            const selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.article-checkbox').forEach((node) => node.checked = this.checked);
                    updateSelectedCount();
                });
            }

            document.querySelectorAll('.article-checkbox').forEach((node) => {
                node.addEventListener('change', updateSelectedCount);
            });

            const batchAction = document.getElementById('batch-action');
            if (batchAction && !IS_TRASH_VIEW) {
                batchAction.addEventListener('change', function() {
                    const statusSelect = document.getElementById('status-select');
                    const reviewSelect = document.getElementById('review-select');
                    statusSelect?.classList.add('hidden');
                    reviewSelect?.classList.add('hidden');
                    if (this.value === 'batch_update_status') {
                        statusSelect?.classList.remove('hidden');
                    } else if (this.value === 'batch_update_review') {
                        reviewSelect?.classList.remove('hidden');
                    }
                });
            }

            const batchForm = document.getElementById('batch-form');
            if (batchForm) {
                batchForm.addEventListener('submit', function(event) {
                    const selected = document.querySelectorAll('.article-checkbox:checked');
                    if (selected.length === 0) {
                        event.preventDefault();
                        alert(IS_TRASH_VIEW ? TRASH_I18N.alertSelect : ARTICLES_I18N.selectArticles);
                        return;
                    }

                    const action = document.getElementById('batch-action')?.value ?? '';
                    if (action === '') {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectAction);
                        return;
                    }

                    const targetAction = ARTICLE_BATCH_ROUTES[action] ?? '';
                    if (targetAction === '') {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectAction);
                        return;
                    }
                    batchForm.action = targetAction;

                    if (IS_TRASH_VIEW) {
                        if (action === 'batch_restore' && !confirm(TRASH_I18N.confirmBatchRestore.replace('__COUNT__', String(selected.length)))) {
                            event.preventDefault();
                            return;
                        }
                        if (action === 'batch_force_delete' && !confirm(TRASH_I18N.confirmBatchForceDelete.replace('__COUNT__', String(selected.length)))) {
                            event.preventDefault();
                            return;
                        }
                    } else {
                    if (action === 'batch_update_status' && !(document.getElementById('status-select')?.value ?? '')) {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectStatus);
                        return;
                    }

                    if (action === 'batch_update_review' && !(document.getElementById('review-select')?.value ?? '')) {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectReview);
                        return;
                    }

                    if (action === 'delete_articles' && !confirm(ARTICLES_I18N.confirmDeleteSelected.replace('__COUNT__', selected.length))) {
                        event.preventDefault();
                        return;
                    }
                    }

                    const selectedIdsContainer = document.getElementById('batch-selected-ids');
                    if (!selectedIdsContainer) {
                        return;
                    }
                    selectedIdsContainer.innerHTML = '';
                    selected.forEach((checkbox) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'article_ids[]';
                        input.value = checkbox.value;
                        selectedIdsContainer.appendChild(input);
                    });
                });
            }
        });
    </script>
@endpush
