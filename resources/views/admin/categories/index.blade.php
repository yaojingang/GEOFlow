@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.categories.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.categories.subtitle') }}</p>
                </div>
                <div class="flex space-x-3">
                    <a href="{{ route('admin.categories.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.categories.add') }}
                    </a>
                    <a href="{{ route('admin.articles.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.categories.back_to_articles') }}
                    </a>
                </div>
            </div>
        </div>
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.categories.list_title') }}</h3>
            </div>
            <div class="overflow-hidden">
                @if (empty($categories))
                    <div class="px-6 py-12 text-center">
                        <i data-lucide="folder-x" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.categories.empty') }}</h3>
                        <p class="text-gray-500 mb-4">{{ __('admin.categories.empty_desc') }}</p>
                        <a href="{{ route('admin.categories.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.categories.add_first') }}
                        </a>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.categories.column_info') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.categories.column_article_count') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.categories.column_sort_order') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.categories.column_created_at') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.tasks.column.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($categories as $category)
                            @php
                                $articleCount = (int) ($category['article_count'] ?? 0);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">{{ $category['name'] }}</div>
                                    <div class="text-xs text-gray-500 mt-1">{{ __('admin.categories.url_label') }}: {{ $category['slug'] }}</div>
                                    @if ((string) ($category['description'] ?? '') !== '')
                                        <div class="text-xs text-gray-500 mt-1">{{ $category['description'] }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">{{ __('admin.categories.article_count_badge', ['count' => $articleCount]) }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ (int) ($category['sort_order'] ?? 0) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ !empty($category['created_at']) ? \Illuminate\Support\Carbon::parse($category['created_at'])->format('Y-m-d H:i') : '-' }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('admin.categories.edit', ['categoryId' => (int) $category['id']]) }}" class="text-blue-600 hover:text-blue-800" title="{{ __('admin.button.edit') }}">
                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                        </a>

                                        @if ($articleCount === 0)
                                            <form method="POST" action="{{ route('admin.categories.delete', ['categoryId' => (int) $category['id']]) }}" class="inline" onsubmit="return confirm(@js(__('admin.categories.confirm_delete')));">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-800" title="{{ __('admin.button.delete') }}">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-gray-400" title="{{ __('admin.categories.delete_disabled') }}">
                                                <i data-lucide="lock" class="w-4 h-4"></i>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
@endsection

