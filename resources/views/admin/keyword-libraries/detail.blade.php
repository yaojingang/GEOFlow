@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-0" data-materials-standalone data-library-detail-actions>
        <header class="mb-6 flex flex-col gap-5 sm:mb-8 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 items-start gap-4">
                    <a href="{{ route('admin.keyword-libraries.index') }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <div class="min-w-0">
                        <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                        <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ $library->description !== '' ? $library->description : __('admin.keyword_detail.no_description') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <a href="{{ route('admin.keyword-libraries.edit', ['libraryId' => (int) $library->id, 'context' => 'detail']) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="edit" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.keyword_detail.edit_info') }}
                    </a>
                    <a href="{{ route('admin.keyword-libraries.import.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="upload" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.import') }}
                    </a>
                    <a href="{{ route('admin.keyword-libraries.keywords.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.keyword_detail.add_keyword') }}
                    </a>
                </div>
        </header>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4 lg:gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="key" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.total_keywords') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $keywords->total() }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.usage_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.created_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->created_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.updated_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->updated_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <form method="GET" class="grid min-w-0 flex-1 grid-cols-2 gap-2 sm:flex sm:items-center sm:gap-3">
                        <div class="col-span-2 min-w-0 sm:max-w-md sm:flex-1">
                            <input type="text" name="search" value="{{ $search }}"
                                placeholder="{{ __('admin.keyword_detail.search_placeholder') }}"
                                class="block min-h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.search') }}
                        </button>
                        <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.clear') }}
                        </a>
                    </form>
                    <div class="flex shrink-0">
                        <button type="button" data-keyword-batch-toggle class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.keyword_detail.batch_actions') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ __('admin.keyword_detail.list_title') }}
                        <span class="text-sm text-gray-500">{{ __('admin.keyword_detail.list_total', ['count' => $keywords->total()]) }}</span>
                    </h3>
                </div>
            </div>

            @if ($keywords->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="search" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.keyword_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.keyword_detail.empty_search') : __('admin.keyword_detail.empty_desc') }}</p>
                    @if ($search === '')
                        <a href="{{ route('admin.keyword-libraries.keywords.create', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.keyword_detail.add_keyword') }}
                        </a>
                    @endif
                </div>
            @else
                <div id="batch-actions" class="hidden border-b border-gray-200 bg-gray-50 px-6 py-3" data-keyword-batch-panel>
                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.delete', ['libraryId' => (int) $library->id]) }}" id="batch-form" data-keyword-batch-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.keyword_detail.confirm_delete_selected', ['count' => '{count}']) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}" data-selected-template="{{ __('admin.keyword_detail.selected_count', ['count' => '{count}']) }}" data-confirm-template="{{ __('admin.keyword_detail.confirm_delete_selected', ['count' => '{count}']) }}">
                        @csrf
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600" id="selected-keyword-count" aria-live="polite" aria-atomic="true" data-keyword-batch-count>{{ __('admin.keyword_detail.selected_count', ['count' => 0]) }}</span>
                            <button type="submit" id="batch-delete-submit" disabled aria-disabled="true" data-keyword-batch-submit data-library-detail-destructive-submit class="inline-flex min-h-10 items-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                {{ __('admin.keyword_detail.delete_selected') }}
                            </button>
                            <button type="button" data-keyword-batch-toggle class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        @foreach ($keywords as $keyword)
                            <div class="group flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="flex items-center space-x-2 min-w-0">
                                    <input type="checkbox" form="batch-form" name="keyword_ids[]" value="{{ (int) $keyword->id }}" data-keyword-batch-checkbox class="hidden rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="text-sm text-gray-900 break-all">{{ $keyword->keyword }}</span>
                                </div>
                                <form method="POST" action="{{ route('admin.keyword-libraries.keywords.delete', ['libraryId' => (int) $library->id]) }}" data-material-delete-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.keyword_detail.confirm_delete_keyword', ['name' => $keyword->keyword]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                    @csrf
                                    <input type="hidden" name="keyword_ids[]" value="{{ (int) $keyword->id }}">
                                    <button type="submit" disabled aria-disabled="true" data-material-delete-submit aria-label="{{ __('admin.common.delete') }}：{{ $keyword->keyword }}" class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-red-600 opacity-60 transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-50 [@media(hover:hover)]:hover:text-red-800 group-hover:opacity-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-30 disabled:active:scale-100">
                                        <i data-lucide="x" class="h-4 w-4"></i>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($keywords->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.keyword_detail.pagination', ['start' => $keywords->firstItem(), 'end' => $keywords->lastItem(), 'total' => $keywords->total()]) }}
                            </div>
                            <div>
                                {{ $keywords->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

@endsection
