@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-0" data-materials-standalone>
        <header class="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="sr-only">
                    <h1>{{ __('admin.title_libraries.heading') }}</h1>
                    <p>{{ __('admin.title_libraries.subtitle') }}</p>
                </div>
                <x-admin.v3.materials-subnav active="titles" />
            </div>
            <a href="{{ route('admin.title-libraries.create') }}" class="inline-flex min-h-10 w-fit items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.title_libraries.create') }}
            </a>
        </header>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4 lg:gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="folder" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_libraries.total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_libraries'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="type" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_libraries.total_titles') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_titles'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="zap" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_libraries.ai_generated') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['ai_titles'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.common.avg_per_library') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (float) ($stats['avg_titles'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.title_libraries.list_title') }}</h3>
            </div>

            @if (empty($libraries))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.title_libraries.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.title_libraries.empty_desc') }}</p>
                    <a href="{{ route('admin.title-libraries.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 transition-[background-color,transform] duration-150 hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.title_libraries.create_first') }}
                    </a>
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($libraries as $library)
                        <div class="px-6 py-6">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="hover:text-green-600">
                                                {{ $library['name'] }}
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                            {{ __('admin.title_libraries.title_count', ['count' => (int) $library['actual_count']]) }}
                                        </span>
                                        @if ((int) ($library['ai_count'] ?? 0) > 0)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                {{ __('admin.title_libraries.ai_count', ['count' => (int) $library['ai_count']]) }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($library['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $library['description'] }}</p>
                                    @endif
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span>
                                            {{ __('admin.title_libraries.created_at', ['value' => $library['created_at'] ? \Illuminate\Support\Carbon::parse($library['created_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        <span>
                                            {{ __('admin.title_libraries.updated_at', ['value' => $library['updated_at'] ? \Illuminate\Support\Carbon::parse($library['updated_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.title-libraries.ai-generate', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-green-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                        <i data-lucide="zap" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.title_detail.ai_generate') }}
                                    </a>
                                    <a href="{{ route('admin.title-libraries.import.create', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.import') }}
                                    </a>
                                    <a href="{{ route('admin.title-libraries.edit', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                        <i data-lucide="pencil" class="mr-1 h-4 w-4"></i>
                                        {{ __('admin.button.edit') }}
                                    </a>
                                    <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.view') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.title-libraries.delete', ['libraryId' => (int) $library['id']]) }}" class="inline-block" data-material-delete-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.title_libraries.confirm_delete', ['name' => $library['name']]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                        @csrf
                                        <button type="submit" disabled aria-disabled="true" data-material-delete-submit class="inline-flex min-h-10 items-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
