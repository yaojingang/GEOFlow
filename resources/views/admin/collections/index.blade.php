@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.collections.heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.collections.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.collections.create') }}" class="inline-flex items-center rounded-md border border-transparent bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                {{ __('admin.collections.create') }}
            </a>
        </div>

        <div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="flex items-center">
                    <i data-lucide="layers-3" class="h-6 w-6 text-slate-700"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.collections.stat_total') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="flex items-center">
                    <i data-lucide="circle-check" class="h-6 w-6 text-emerald-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.collections.stat_active') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="flex items-center">
                    <i data-lucide="database" class="h-6 w-6 text-blue-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.collections.stat_used') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['used'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6 rounded-lg bg-white shadow">
            <div class="px-6 py-4">
                <form method="GET" action="{{ route('admin.collections.index') }}" class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-center">
                    <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('admin.collections.search_placeholder') }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.search') }}
                    </button>
                    <a href="{{ route('admin.collections.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="x" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.clear') }}
                    </a>
                </form>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('admin.collections.list_title') }}
                    <span class="text-sm text-gray-500">({{ (int) $collections->total() }})</span>
                </h3>
            </div>

            @if ($collections->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.collections.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.collections.column_collection') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.collections.column_status') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.collections.column_materials') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($collections as $collection)
                                @php
                                    $usageCount = (int) ($collection->knowledge_bases_count ?? 0)
                                        + (int) ($collection->entities_count ?? 0)
                                        + (int) ($collection->cases_count ?? 0)
                                        + (int) ($collection->keyword_libraries_count ?? 0)
                                        + (int) ($collection->title_libraries_count ?? 0)
                                        + (int) ($collection->image_libraries_count ?? 0);
                                @endphp
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900">{{ $collection->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $collection->slug }}</div>
                                        @if ((string) ($collection->description ?? '') !== '')
                                            <div class="mt-2 max-w-2xl text-sm leading-6 text-gray-600">{{ $collection->description }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        @if ($collection->isActive())
                                            <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">{{ __('admin.collections.status_active') }}</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">{{ __('admin.collections.status_inactive') }}</span>
                                        @endif
                                        <div class="mt-2 text-xs text-gray-500">{{ __('admin.collections.sort_order', ['value' => (int) $collection->sort_order]) }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex max-w-xl flex-wrap gap-2 text-xs font-medium">
                                            <span class="rounded bg-orange-50 px-2 py-1 text-orange-700">{{ __('admin.collections.count_knowledge', ['count' => (int) ($collection->knowledge_bases_count ?? 0)]) }}</span>
                                            <span class="rounded bg-blue-50 px-2 py-1 text-blue-700">{{ __('admin.collections.count_entities', ['count' => (int) ($collection->entities_count ?? 0)]) }}</span>
                                            <span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">{{ __('admin.collections.count_cases', ['count' => (int) ($collection->cases_count ?? 0)]) }}</span>
                                            <span class="rounded bg-indigo-50 px-2 py-1 text-indigo-700">{{ __('admin.collections.count_keywords', ['count' => (int) ($collection->keyword_libraries_count ?? 0)]) }}</span>
                                            <span class="rounded bg-green-50 px-2 py-1 text-green-700">{{ __('admin.collections.count_titles', ['count' => (int) ($collection->title_libraries_count ?? 0)]) }}</span>
                                            <span class="rounded bg-purple-50 px-2 py-1 text-purple-700">{{ __('admin.collections.count_images', ['count' => (int) ($collection->image_libraries_count ?? 0)]) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <form method="POST" action="{{ route('admin.collections.default', ['collectionId' => (int) $collection->id]) }}" class="inline-flex">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded border px-3 py-1.5 text-xs font-medium {{ (int) \App\Support\AdminWeb::defaultCollectionId() === (int) $collection->id ? 'border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}" title="{{ (int) \App\Support\AdminWeb::defaultCollectionId() === (int) $collection->id ? '取消默认' : '设为默认业务容器' }}">
                                                    <i data-lucide="{{ (int) \App\Support\AdminWeb::defaultCollectionId() === (int) $collection->id ? 'star' : 'star-off' }}" class="h-4 w-4"></i>
                                                </button>
                                            </form>
                                            <a href="{{ route('admin.collections.edit', ['collectionId' => (int) $collection->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                <i data-lucide="pencil" class="mr-1 h-4 w-4"></i>
                                                {{ __('admin.button.edit') }}
                                            </a>
                                            <form method="POST" action="{{ route('admin.collections.toggle', ['collectionId' => (int) $collection->id]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                    <i data-lucide="{{ $collection->isActive() ? 'pause-circle' : 'play-circle' }}" class="mr-1 h-4 w-4"></i>
                                                    {{ $collection->isActive() ? __('admin.collections.action_disable') : __('admin.collections.action_enable') }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.collections.delete', ['collectionId' => (int) $collection->id]) }}" onsubmit="return confirm(@js(__('admin.collections.confirm_delete', ['name' => $collection->name])));">
                                                @csrf
                                                <button type="submit" @disabled($usageCount > 0) class="inline-flex items-center rounded border border-transparent px-3 py-1.5 text-xs font-medium text-white {{ $usageCount > 0 ? 'cursor-not-allowed bg-gray-300' : 'bg-red-600 hover:bg-red-700' }}">
                                                    <i data-lucide="trash-2" class="mr-1 h-4 w-4"></i>
                                                    {{ __('admin.button.delete') }}
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="text-sm text-gray-600">
                        {{ __('admin.collections.pagination_summary', [
                            'from' => $collections->firstItem() ?? 0,
                            'to' => $collections->lastItem() ?? 0,
                            'total' => $collections->total(),
                        ]) }}
                    </div>
                    @if ($collections->lastPage() > 1)
                        <div>{{ $collections->links() }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
