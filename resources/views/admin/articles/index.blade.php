@extends('admin.layouts.app')

@php
    $isTrashView = (bool) ($isTrashView ?? false);
    $selectedTaskId = (int) ($filters['task_id'] ?? 0);
    $selectedStatus = (string) ($filters['status'] ?? '');
    $selectedReviewStatus = (string) ($filters['review_status'] ?? '');
    $selectedAiQualityStatus = (string) ($filters['ai_quality_status'] ?? '');
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
    $articleListAnchor = '#article-list';
    $reviewCenterUrl = route('admin.articles.index', ['review_status' => 'pending']).$articleListAnchor;
    $trashUrl = route('admin.articles.index', ['trashed' => 1]);
    $articlesIndexUrl = route('admin.articles.index');
    $clearTaskFilterUrl = route('admin.articles.index', request()->except(['task_id', 'page']));
    $adminUiV3Enabled = (bool) config('geoflow.admin_ui_v3_enabled', false);
    $articleNavigationActive = $isTrashView
        ? 'trash'
        : ($selectedReviewStatus === 'pending' ? 'review' : 'article-list');
@endphp

@section('topbar-title', $isTrashView ? __('admin.articles.trash.title') : __('admin.articles.topbar_title'))
@section('topbar-icon', $isTrashView ? 'trash-2' : 'file-text')

@section('content')
    <div class="px-4 sm:px-0">
        <header class="mb-6 flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0 flex-1">
                @if($adminUiV3Enabled)
                    <h1 class="sr-only">{{ $pageTitle }}</h1>
                @else
                    <h1 class="text-3xl font-bold leading-9 tracking-tight text-gray-900">{{ $pageTitle }}</h1>
                    <p class="mt-2 text-[15px] leading-6 text-gray-600">{{ $isTrashView ? __('admin.articles.trash.subtitle') : __('admin.articles.page_subtitle') }}</p>
                @endif
                <div @class(['mt-3' => !$adminUiV3Enabled])>
                    <x-admin.v3.articles-subnav :active="$articleNavigationActive" />
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap justify-start gap-2 xl:justify-end">
                @if($isTrashView)
                    <a href="{{ $articlesIndexUrl }}" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        <i data-lucide="arrow-left" class="h-4 w-4"></i>
                        {{ __('admin.articles.trash.back') }}
                    </a>
                    <button type="button" onclick="submitEmptyTrash()" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-red-200 bg-white px-4 text-sm font-semibold text-red-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-red-300 [@media(hover:hover)]:hover:bg-red-50 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">
                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                        {{ __('admin.articles.trash.empty') }}
                    </button>
                @else
                    <a href="{{ route('admin.articles.create') }}" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-blue-600 bg-blue-600 px-4 text-sm font-semibold text-white transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-blue-700 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        <i data-lucide="plus" class="h-4 w-4"></i>
                        {{ __('admin.button.create_article') }}
                    </a>
                    <a href="{{ route('admin.manual-publications.index') }}" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition-[background-color,border-color,transform] duration-150 [@media(hover:hover)]:hover:border-gray-400 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        <i data-lucide="send" class="h-4 w-4"></i>
                        {{ __('admin.manual_publications.nav') }}
                    </a>
                @endif
            </div>
        </header>

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
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-7 gap-4">
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
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.ai_quality_status') }}</label>
                            <select name="ai_quality_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_ai_quality') }}</option>
                                <option value="passed" @selected($selectedAiQualityStatus === 'passed')>{{ __('admin.articles.ai_quality.passed') }}</option>
                                <option value="needs_review" @selected($selectedAiQualityStatus === 'needs_review')>{{ __('admin.articles.ai_quality.needs_review') }}</option>
                                <option value="blocked" @selected($selectedAiQualityStatus === 'blocked')>{{ __('admin.articles.ai_quality.blocked') }}</option>
                                <option value="pending" @selected($selectedAiQualityStatus === 'pending')>{{ __('admin.articles.ai_quality.pending') }}</option>
                                <option value="failed" @selected($selectedAiQualityStatus === 'failed')>{{ __('admin.articles.ai_quality.failed') }}</option>
                                <option value="stale" @selected($selectedAiQualityStatus === 'stale')>{{ __('admin.articles.ai_quality.stale') }}</option>
                                <option value="disabled" @selected($selectedAiQualityStatus === 'disabled')>{{ __('admin.articles.ai_quality.disabled_short') }}</option>
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

        <div id="article-list" class="scroll-mt-24 bg-white shadow rounded-lg">
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
                        <button type="button" onclick="toggleBatchActions()" data-article-batch-control class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
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
                    <form method="POST" action="{{ \App\Support\AdminWeb::routePath('admin.articles.batch.update-status') }}" id="batch-form" data-csrf-token="{{ csrf_token() }}">
                        @csrf
                        <div id="batch-selected-ids"></div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-gray-600">
                                @if(__('admin.articles.bulk.selected_prefix') !== '')
                                    <span>{{ __('admin.articles.bulk.selected_prefix') }}</span>
                                @endif
                                <span id="selected-count">0</span>
                                <span>{{ __('admin.articles.bulk.selected_suffix') }}</span>
                            </span>
                            <select name="action" id="batch-action" data-article-batch-control class="border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm disabled:cursor-wait disabled:opacity-60">
                                <option value="">{{ __('admin.articles.bulk.select_action') }}</option>
                                @if($isTrashView)
                                    <option value="batch_restore">{{ __('admin.articles.trash.action_restore') }}</option>
                                    <option value="batch_force_delete">{{ __('admin.articles.trash.action_force_delete') }}</option>
                                @else
                                    <option value="batch_update_status">{{ __('admin.articles.bulk.status_to') }}</option>
                                    <option value="batch_update_review">{{ __('admin.articles.bulk.review_to') }}</option>
                                    <option value="export_markdown" data-article-batch-export-option disabled>{{ __('admin.articles.export.action') }}</option>
                                    <option value="delete_articles">{{ __('admin.articles.bulk.delete') }}</option>
                                @endif
                            </select>
                            @if(!$isTrashView)
                            <select name="new_status" id="status-select" data-article-batch-control class="hidden border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm disabled:cursor-wait disabled:opacity-60">
                                <option value="draft">{{ __('admin.articles.status.draft') }}</option>
                                <option value="published">{{ __('admin.articles.status.published') }}</option>
                                <option value="private">{{ __('admin.articles.status.private') }}</option>
                            </select>
                            <select name="review_status" id="review-select" data-article-batch-control class="hidden border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm disabled:cursor-wait disabled:opacity-60">
                                <option value="pending">{{ __('admin.articles.review.pending') }}</option>
                                <option value="approved">{{ __('admin.articles.review.approved') }}</option>
                                <option value="rejected">{{ __('admin.articles.review.rejected') }}</option>
                                <option value="auto_approved">{{ __('admin.articles.review.auto_approved') }}</option>
                            </select>
                            @endif
                            <button type="submit" data-batch-execute data-article-batch-control class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                                {{ __('admin.button.execute') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" data-article-batch-control class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1080px] table-fixed divide-y divide-gray-200" data-sticky-actions data-article-list-table>
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="batch-checkbox hidden w-12 px-3 py-3 text-left">
                                <input type="checkbox" id="select-all" data-article-batch-control class="rounded border-gray-300 text-blue-600 shadow-sm disabled:cursor-wait disabled:opacity-60">
                            </th>
                            <th class="w-16 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.id') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.info') }}</th>
                            <th class="w-40 px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.task_author') }}</th>
                            @if(!$isTrashView)
                            <th class="w-36 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.workflow') }}</th>
                            <th class="w-36 px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.ai_quality') }}</th>
                            @endif
                            <th class="w-40 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $isTrashView ? __('admin.articles.trash.column.deleted_at') : __('admin.articles.column.created_at') }}</th>
                            <th class="w-36 py-3 pl-3 pr-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.actions') }}</th>
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
                                $publishStatusLabel = __('admin.articles.publish_prefix').': '.__('admin.articles.status.'.(string) $article->status);
                                $reviewStatusLabel = __('admin.articles.review_prefix').': '.__('admin.articles.review.'.(string) $article->review_status);
                                $distributionTotal = (int) ($article->distribution_total_count ?? 0);
                                $aiQualityCheck = $article->latestAiQualityCheck;
                                $aiQualityEnabled = (bool) $article->ai_quality_required_at_creation
                                    || (bool) ($article->task->ai_quality_enabled ?? false);
                                $aiQualityPresentation = [
                                    'label' => __('admin.articles.ai_quality.disabled_short'),
                                    'class' => 'bg-gray-100 text-gray-600 ring-gray-200',
                                    'icon' => 'shield-off',
                                ];
                                if ($aiQualityEnabled && $aiQualityCheck === null) {
                                    $aiQualityPresentation = ['label' => __('admin.articles.ai_quality.pending'), 'class' => 'bg-sky-50 text-sky-700 ring-sky-100', 'icon' => 'loader-circle'];
                                } elseif ($aiQualityCheck !== null) {
                                    $aiQualityPresentation = match (true) {
                                        in_array((string) $aiQualityCheck->status, ['queued', 'running'], true) => ['label' => __('admin.articles.ai_quality.pending'), 'class' => 'bg-sky-50 text-sky-700 ring-sky-100', 'icon' => 'loader-circle'],
                                        (string) $aiQualityCheck->status === 'stale' => ['label' => __('admin.articles.ai_quality.stale'), 'class' => 'bg-slate-100 text-slate-700 ring-slate-200', 'icon' => 'refresh-cw'],
                                        (string) $aiQualityCheck->status === 'failed' || (string) $aiQualityCheck->decision === 'error' => ['label' => __('admin.articles.ai_quality.failed'), 'class' => 'bg-red-50 text-red-700 ring-red-100', 'icon' => 'triangle-alert'],
                                        (string) $aiQualityCheck->decision === 'passed' => ['label' => __('admin.articles.ai_quality.passed'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'icon' => 'shield-check'],
                                        (string) $aiQualityCheck->decision === 'needs_review' && (bool) $aiQualityCheck->is_overridden => ['label' => __('admin.articles.ai_quality.overridden'), 'class' => 'bg-blue-50 text-blue-700 ring-blue-100', 'icon' => 'user-check'],
                                        (string) $aiQualityCheck->decision === 'needs_review' => ['label' => __('admin.articles.ai_quality.needs_review'), 'class' => 'bg-amber-50 text-amber-700 ring-amber-100', 'icon' => 'user-round-check'],
                                        default => ['label' => __('admin.articles.ai_quality.blocked'), 'class' => 'bg-red-50 text-red-700 ring-red-100', 'icon' => 'shield-x'],
                                    };
                                }
                                $aiQualityScore = $aiQualityCheck?->score === null ? null : (int) $aiQualityCheck->score;
                                $aiQualityAccessibleLabel = $aiQualityPresentation['label'];
                                if ($aiQualityScore !== null) {
                                    $aiQualityAccessibleLabel .= ' · '.__('admin.articles.ai_quality.score').' '.$aiQualityScore;
                                }
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
                                <td class="batch-checkbox hidden px-3 py-4">
                                    <input type="checkbox" value="{{ (int) $article->id }}" class="article-checkbox rounded border-gray-300 text-blue-600 shadow-sm">
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">#{{ (int) $article->id }}</td>
                                <td class="px-4 py-4">
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
                                <td class="px-4 py-4 text-sm text-gray-500">
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
                                <td class="px-3 py-4 align-top">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex max-w-full self-start rounded px-2 py-0.5 text-xs font-medium {{ $statusClass }}" title="{{ $publishStatusLabel }}">
                                            <span class="min-w-0 whitespace-normal break-words leading-4 [overflow-wrap:anywhere]">{{ $publishStatusLabel }}</span>
                                        </span>
                                        <span class="inline-flex max-w-full self-start rounded px-2 py-0.5 text-xs font-medium {{ $reviewClass }}" title="{{ $reviewStatusLabel }}">
                                            <span class="min-w-0 whitespace-normal break-words leading-4 [overflow-wrap:anywhere]">{{ $reviewStatusLabel }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <a
                                        href="{{ route('admin.articles.edit', ['articleId' => (int) $article->id]).'#ai-quality-result' }}"
                                        aria-label="{{ $aiQualityAccessibleLabel }}"
                                        title="{{ $aiQualityAccessibleLabel }}"
                                        @if($aiQualityScore !== null) data-ai-quality-score-badge="{{ $aiQualityScore }}" @endif
                                        class="inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $aiQualityPresentation['class'] }}"
                                    >
                                        <i data-lucide="{{ $aiQualityPresentation['icon'] }}" aria-hidden="true" class="h-3.5 w-3.5 shrink-0"></i>
                                        @if($aiQualityScore !== null)
                                            <span class="shrink-0 font-mono">{{ $aiQualityScore }}</span>
                                        @else
                                            <span class="truncate">{{ $aiQualityPresentation['label'] }}</span>
                                        @endif
                                    </a>
                                </td>
                                @endif
                                <td class="px-3 py-4 whitespace-nowrap text-sm leading-5 text-gray-500">
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
                                <td class="py-4 pl-3 pr-4 whitespace-nowrap text-sm font-medium">
                                    @if($isTrashView)
                                        <div class="flex items-center justify-end gap-2">
                                            <form method="POST" action="{{ route('admin.articles.restore', ['articleId' => (int) $article->id]) }}" class="inline" data-admin-confirm-form data-admin-confirm-tone="success" data-admin-confirm-title="{{ __('admin.articles.trash.confirm_restore') }}" data-admin-confirm-message="{{ __('admin.action_dialog.target', ['name' => $article->title]) }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.articles.trash.action_restore') }}">
                                                @csrf
                                                <button type="submit" class="text-green-600 hover:text-green-800" title="{{ __('admin.articles.trash.action_restore') }}" data-admin-confirm-submit disabled aria-disabled="true">
                                                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.articles.force-delete', ['articleId' => (int) $article->id]) }}" class="inline" data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.articles.trash.confirm_delete') }}" data-admin-confirm-message="{{ __('admin.action_dialog.target', ['name' => $article->title]) }}" data-admin-confirm-guidance="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.articles.trash.action_force_delete') }}">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-800" title="{{ __('admin.articles.trash.action_force_delete') }}" data-admin-confirm-submit disabled aria-disabled="true">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <div class="flex items-center justify-end gap-2">
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
                                            @if($canCreateManualPublication && in_array((string) $article->review_status, ['approved', 'auto_approved'], true))
                                                <a href="{{ route('admin.manual-publications.create', ['article_id' => (int) $article->id]) }}" class="text-purple-600 hover:text-purple-800" title="{{ __('admin.manual_publications.article_action') }}">
                                                    <i data-lucide="send" class="w-4 h-4"></i>
                                                </a>
                                            @endif
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
    @if(!$isTrashView)
        <dialog
            data-article-batch-export
            data-prepare-url="{{ \App\Support\AdminWeb::routePath('admin.articles.batch.export-markdown.prepare') }}"
            data-max-articles="{{ $articleExportMaxArticles }}"
            data-select-articles-message="{{ __('admin.articles.export.errors.select_articles') }}"
            data-too-many-message="{{ __('admin.articles.export.errors.too_many', ['max' => $articleExportMaxArticles]) }}"
            data-invalid-response-message="{{ __('admin.articles.export.errors.invalid_response') }}"
            data-network-error-message="{{ __('admin.articles.export.errors.network') }}"
            data-expired-message="{{ __('admin.articles.export.errors.expired') }}"
            data-csrf-expired-message="{{ __('admin.articles.export.errors.csrf_expired') }}"
            data-rate-limited-message="{{ __('admin.articles.export.errors.rate_limited') }}"
            data-request-too-large-message="{{ __('admin.articles.export.errors.request_too_large') }}"
            aria-modal="true"
            aria-labelledby="article-export-dialog-label"
            class="m-auto max-h-[calc(100dvh-2rem)] w-[min(30rem,calc(100vw-2rem))] overflow-y-auto overscroll-contain rounded-2xl border border-slate-200 bg-white p-0 text-left shadow-[0_24px_72px_rgba(15,23,42,0.28)] backdrop:bg-[rgba(15,23,42,0.48)]"
        >
            <h2 id="article-export-dialog-label" class="sr-only">{{ __('admin.articles.export.dialog_label') }}</h2>
            <div data-export-state="loading" role="status" aria-live="polite" aria-busy="true" class="px-6 py-7 sm:px-8 sm:py-8">
                <div class="flex items-start gap-4">
                    <div class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-50 ring-1 ring-blue-100">
                        <span class="absolute h-9 w-9 animate-ping rounded-full bg-blue-200/50 motion-reduce:animate-none"></span>
                        <i data-lucide="archive" class="relative h-5 w-5 text-blue-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">{{ __('admin.articles.export.loading_eyebrow') }}</p>
                        <h2 data-export-loading-focus tabindex="-1" class="mt-1 text-lg font-semibold text-slate-950 outline-none">{{ __('admin.articles.export.loading_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('admin.articles.export.loading_desc') }}</p>
                    </div>
                </div>
                <div class="mt-6 rounded-xl border border-blue-100 bg-blue-50/70 px-4 py-3">
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-sm font-medium text-blue-950">
                            {{ __('admin.articles.export.selected_prefix') }}
                            <span data-export-selected-count>0</span>
                            {{ __('admin.articles.export.selected_suffix') }}
                        </p>
                        <div class="flex items-center gap-1.5" aria-hidden="true">
                            <span class="h-2 w-2 animate-pulse rounded-full bg-blue-600 motion-reduce:animate-none"></span>
                            <span class="h-2 w-2 animate-pulse rounded-full bg-blue-500 [animation-delay:150ms] motion-reduce:animate-none"></span>
                            <span class="h-2 w-2 animate-pulse rounded-full bg-blue-400 [animation-delay:300ms] motion-reduce:animate-none"></span>
                        </div>
                    </div>
                    <p class="mt-1 text-xs leading-5 text-blue-700">{{ __('admin.articles.export.loading_help', ['max' => $articleExportMaxArticles]) }}</p>
                </div>
            </div>

            <div data-export-state="success" hidden role="status" aria-live="polite" class="px-6 py-7 sm:px-8 sm:py-8">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 ring-1 ring-emerald-100">
                        <i data-lucide="circle-check-big" class="h-6 w-6 text-emerald-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 data-export-success-focus tabindex="-1" class="text-lg font-semibold text-slate-950 outline-none">{{ __('admin.articles.export.success_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('admin.articles.export.success_desc') }}</p>
                    </div>
                </div>
                <div class="mt-5 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <i data-lucide="file-archive" class="h-5 w-5 shrink-0 text-slate-500"></i>
                    <span data-export-filename class="min-w-0 truncate text-sm font-medium text-slate-700"></span>
                </div>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button type="button" data-export-close class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        {{ __('admin.articles.export.close') }}
                    </button>
                    <button type="button" data-export-retry class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 active:scale-[0.98]">
                        <i data-lucide="download" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.articles.export.retry_download') }}
                    </button>
                </div>
            </div>

            <div data-export-state="error" hidden role="alert" aria-live="assertive" class="px-6 py-7 sm:px-8 sm:py-8">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-red-50 ring-1 ring-red-100">
                        <i data-lucide="circle-alert" class="h-6 w-6 text-red-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 data-export-error-focus tabindex="-1" class="text-lg font-semibold text-slate-950 outline-none">{{ __('admin.articles.export.error_title') }}</h2>
                        <p data-export-error-message class="mt-2 text-sm leading-6 text-red-700"></p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('admin.articles.export.error_help') }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="button" data-export-close class="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 active:scale-[0.98]">
                        {{ __('admin.articles.export.close') }}
                    </button>
                </div>
            </div>
        </dialog>
    @endif
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
        const ARTICLE_DIALOG_I18N = {
            confirm: @json(__('admin.action_dialog.continue')),
            close: @json(__('admin.action_dialog.close')),
            guidance: @json(__('admin.action_dialog.generic_impact')),
            noticeTitle: @json(__('admin.action_dialog.info_title')),
        };

        function showArticleNotice(message, focusTarget = null) {
            window.AdminActionDialog?.notice?.({
                tone: 'info',
                title: ARTICLE_DIALOG_I18N.noticeTitle,
                message,
            });
            focusTarget?.focus?.({ preventScroll: true });
        }

        async function confirmArticleAction(title, tone = 'danger', opener = null) {
            return await window.AdminActionDialog?.confirm?.({
                title,
                message: ARTICLE_DIALOG_I18N.guidance,
                tone,
                confirmLabel: ARTICLE_DIALOG_I18N.confirm,
                opener,
            }) === true;
        }

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

        async function submitEmptyTrash() {
            if (!await confirmArticleAction(TRASH_I18N.confirmEmpty, 'danger', document.activeElement)) {
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

        async function deleteArticle(articleId) {
            if (!await confirmArticleAction(ARTICLES_I18N.confirmDelete, 'danger', document.activeElement)) {
                return;
            }
            submitAction('delete_articles', articleId);
        }

        async function quickReview(articleId, status) {
            const actionText = status === 'approved' ? ARTICLES_I18N.reviewApproved : ARTICLES_I18N.reviewRejected;
            if (!await confirmArticleAction(ARTICLES_I18N.confirmQuickReview.replace('__ACTION__', actionText), 'info', document.activeElement)) {
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
                batchForm.addEventListener('submit', async function(event) {
                    if (batchForm.dataset.articleBatchConfirmed === 'true') {
                        delete batchForm.dataset.articleBatchConfirmed;
                        return;
                    }
                    event.preventDefault();
                    const selected = document.querySelectorAll('.article-checkbox:checked');
                    if (selected.length === 0) {
                        showArticleNotice(IS_TRASH_VIEW ? TRASH_I18N.alertSelect : ARTICLES_I18N.selectArticles, document.getElementById('select-all'));
                        return;
                    }

                    const action = document.getElementById('batch-action')?.value ?? '';
                    if (action === '') {
                        showArticleNotice(ARTICLES_I18N.selectAction, document.getElementById('batch-action'));
                        return;
                    }

                    const targetAction = ARTICLE_BATCH_ROUTES[action] ?? '';
                    if (targetAction === '') {
                        showArticleNotice(ARTICLES_I18N.selectAction, document.getElementById('batch-action'));
                        return;
                    }
                    batchForm.action = targetAction;

                    if (IS_TRASH_VIEW) {
                        if (action === 'batch_restore' && !await confirmArticleAction(TRASH_I18N.confirmBatchRestore.replace('__COUNT__', String(selected.length)), 'success', event.submitter)) {
                            return;
                        }
                        if (action === 'batch_force_delete' && !await confirmArticleAction(TRASH_I18N.confirmBatchForceDelete.replace('__COUNT__', String(selected.length)), 'danger', event.submitter)) {
                            return;
                        }
                    } else {
                    if (action === 'batch_update_status' && !(document.getElementById('status-select')?.value ?? '')) {
                        showArticleNotice(ARTICLES_I18N.selectStatus, document.getElementById('status-select'));
                        return;
                    }

                    if (action === 'batch_update_review' && !(document.getElementById('review-select')?.value ?? '')) {
                        showArticleNotice(ARTICLES_I18N.selectReview, document.getElementById('review-select'));
                        return;
                    }

                    if (action === 'delete_articles' && !await confirmArticleAction(ARTICLES_I18N.confirmDeleteSelected.replace('__COUNT__', selected.length), 'danger', event.submitter)) {
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
                    batchForm.dataset.articleBatchConfirmed = 'true';
                    batchForm.requestSubmit(event.submitter instanceof HTMLButtonElement ? event.submitter : undefined);
                });
            }
        });
    </script>
@endpush
