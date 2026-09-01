@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0" data-materials-standalone data-author-index>
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="sr-only">
                    <h1>{{ __('admin.authors.page_title') }}</h1>
                    <p>{{ __('admin.authors.page_subtitle') }}</p>
                </div>
                <x-admin.v3.materials-subnav active="authors" />
            </div>
            <a href="{{ route('admin.authors.create') }}" class="inline-flex min-h-10 w-fit items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.authors.create') }}
            </a>
        </header>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:gap-6 [&>*:last-child]:col-span-2 md:[&>*:last-child]:col-span-1">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.authors.stats_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_authors'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="user-check" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.authors.stats_active') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['active_authors'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.authors.stats_average') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (float) ($stats['avg_articles'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                    <div class="flex-1 min-w-0">
                        <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('admin.authors.search_placeholder') }}" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <button type="submit" class="inline-flex min-h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.search') }}
                    </button>
                    <a href="{{ route('admin.authors.index') }}" class="inline-flex min-h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.clear') }}
                    </a>
                </form>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('admin.authors.list_title') }}
                    <span class="text-sm text-gray-500">({{ (int) ($authorsPagination?->total() ?? 0) }})</span>
                </h3>
            </div>
            @if (empty($authors))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="user-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.authors.empty_title') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.authors.empty_search') : __('admin.authors.empty_desc') }}</p>
                    @if ($search === '')
                        <a href="{{ route('admin.authors.create') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.authors.create') }}
                        </a>
                    @endif
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($authors as $author)
                        <div class="px-6 py-6">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex min-w-0 items-start gap-4">
                                    <div class="shrink-0">
                                        <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                                            <i data-lucide="user" class="w-6 h-6 text-indigo-600"></i>
                                        </div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h4 class="break-words text-lg font-medium text-gray-900">{{ $author['name'] }}</h4>
                                        @if ($author['email'] !== '')
                                            <p class="text-sm text-gray-600">{{ $author['email'] }}</p>
                                        @endif
                                        @if ($author['bio'] !== '')
                                            <p class="text-sm text-gray-500 mt-1">
                                                {{ \Illuminate\Support\Str::limit($author['bio'], 100, '...') }}
                                            </p>
                                        @endif
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
                                            <span>{{ __('admin.authors.article_count', ['count' => (int) $author['article_count']]) }}</span>
                                            <span>{{ __('admin.authors.published_count', ['count' => (int) $author['published_count']]) }}</span>
                                            @if ((int) $author['trashed_count'] > 0)
                                                <span>{{ __('admin.authors.trashed_count', ['count' => (int) $author['trashed_count']]) }}</span>
                                            @endif
                                            <span>{{ __('admin.authors.created_prefix', ['date' => $author['created_at'] ? \Illuminate\Support\Carbon::parse($author['created_at'])->format('Y-m-d') : '-']) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.authors.edit', ['authorId' => (int) $author['id']]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                        <i data-lucide="pencil" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.authors.edit') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.authors.delete', ['authorId' => (int) $author['id']]) }}" data-material-delete-form data-author-delete-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ (int) $author['trashed_count'] > 0 ? __('admin.authors.confirm_delete_trashed', ['name' => $author['name'], 'count' => (int) $author['trashed_count']]) : __('admin.authors.confirm_delete', ['name' => $author['name']]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                        @csrf
                                        <button type="submit" disabled aria-disabled="true" data-material-delete-submit data-author-delete-submit class="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.authors.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if (($authorsPagination?->lastPage() ?? 1) > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.articles.pagination.summary', [
                                    'from' => (string) ($authorsPagination?->firstItem() ?? 0),
                                    'to' => (string) ($authorsPagination?->lastItem() ?? 0),
                                    'total' => (string) ($authorsPagination?->total() ?? 0),
                                ]) }}
                            </div>
                            <div class="flex flex-wrap gap-1">
                                @if (($authorsPagination?->currentPage() ?? 1) > 1)
                                    <a href="{{ $authorsPagination?->url(($authorsPagination?->currentPage() ?? 2) - 1) }}" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        {{ __('admin.articles.pagination.prev') }}
                                    </a>
                                @endif

                                @php
                                    $currentPage = (int) ($authorsPagination?->currentPage() ?? 1);
                                    $lastPage = (int) ($authorsPagination?->lastPage() ?? 1);
                                @endphp
                                @for ($i = max(1, $currentPage - 2); $i <= min($lastPage, $currentPage + 2); $i++)
                                    <a href="{{ $authorsPagination?->url($i) }}"
                                       class="px-3 py-2 text-sm font-medium {{ $i === $currentPage ? 'text-indigo-600 bg-indigo-50 border-indigo-500' : 'text-gray-500 bg-white border-gray-300' }} border rounded-md hover:bg-gray-50">
                                        {{ $i }}
                                    </a>
                                @endfor

                                @if (($authorsPagination?->currentPage() ?? 1) < ($authorsPagination?->lastPage() ?? 1))
                                    <a href="{{ $authorsPagination?->url(($authorsPagination?->currentPage() ?? 0) + 1) }}" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        {{ __('admin.articles.pagination.next') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

@endsection
