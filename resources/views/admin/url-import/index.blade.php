@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0 space-y-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.materials.index') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.url_import.page_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.url_import.page_subtitle') }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.url-import.history') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="history" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.url_import.button.view_history') }}
                </a>
                <a href="{{ route('admin.materials.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.url_import.button.back_to_materials') }}
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.url_import.stats.knowledge_bases') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ __('admin.url_import.value.count_units', ['count' => (int) $stats['knowledge_bases']]) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.url_import.stats.keyword_libraries') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ __('admin.url_import.value.count_units', ['count' => (int) $stats['keyword_libraries']]) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.url_import.stats.title_libraries') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ __('admin.url_import.value.count_units', ['count' => (int) $stats['title_libraries']]) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.url_import.stats.image_libraries') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ __('admin.url_import.value.count_units', ['count' => (int) $stats['image_libraries']]) }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.url-import.store') }}" class="bg-white shadow rounded-lg overflow-hidden">
            @csrf
            <div class="px-6 py-5 border-b border-gray-200">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center rounded-full bg-cyan-50 px-3 py-1 text-sm font-medium text-cyan-700">
                        <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.url_import') }}
                    </span>
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-sm font-medium text-amber-700">
                        <i data-lucide="triangle-alert" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.url_import_warning') }}
                    </span>
                </div>
                <h2 class="mt-5 text-xl font-semibold text-gray-900">{{ __('admin.url_import.section.new_job') }}</h2>
                <p class="mt-2 text-sm text-gray-600">{{ __('admin.url_import.section.new_job_desc') }}</p>
            </div>

            <div class="p-6 grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 space-y-6">
                    <div>
                        <label for="url" class="block text-sm font-medium text-gray-700">{{ __('admin.url_import.field.url') }}</label>
                        <input
                            id="url"
                            name="url"
                            type="url"
                            required
                            value="{{ old('url') }}"
                            placeholder="{{ __('admin.materials.url_import_placeholder') }}"
                            class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        >
                        <p class="mt-2 text-xs text-gray-500">{{ __('admin.url_import.help.url') }}</p>
                        @error('url')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.url_import.field.project_name') }}</label>
                            <input name="project_name" value="{{ old('project_name') }}" placeholder="{{ __('admin.url_import.placeholder.project_name') }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.url_import.field.source_label') }}</label>
                            <input name="source_label" value="{{ old('source_label') }}" placeholder="{{ __('admin.url_import.placeholder.source_label') }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.url_import.field.content_language') }}</label>
                            <select name="content_language" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.url_import.option.auto_detect') }}</option>
                                <option value="zh-CN">中文</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.url_import.field.author') }}</label>
                            <select disabled class="mt-2 block w-full rounded-md border-gray-300 bg-gray-50 shadow-sm sm:text-sm">
                                <option>{{ __('admin.url_import.option.not_specified') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('admin.url_import.field.notes') }}</label>
                        <textarea name="notes" rows="3" placeholder="{{ __('admin.url_import.placeholder.notes') }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">{{ old('notes') }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach (['knowledge', 'keywords', 'titles', 'images'] as $output)
                            <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                                <input type="checkbox" name="outputs[]" value="{{ $output }}" checked class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span>
                                    <span class="block text-sm font-medium text-gray-900">{{ __('admin.url_import.output.' . $output) }}</span>
                                    <span class="block mt-1 text-xs text-gray-500">{{ __('admin.url_import.option.create_or_later') }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-lg border border-gray-200 p-4">
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.url_import.section.scope') }}</h3>
                        <ul class="mt-3 space-y-2 text-sm text-gray-600">
                            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-green-600 mt-0.5"></i>{{ __('admin.url_import.scope.single_page') }}</li>
                            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-green-600 mt-0.5"></i>{{ __('admin.url_import.scope.preview_first') }}</li>
                            <li class="flex gap-2"><i data-lucide="shield" class="w-4 h-4 text-blue-600 mt-0.5"></i>{{ __('admin.url_import.scope.security') }}</li>
                        </ul>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <h3 class="text-sm font-semibold text-amber-900">{{ __('admin.url_import.section.recommendation') }}</h3>
                        <p class="mt-2 text-sm text-amber-800">{{ __('admin.url_import.recommendation.copy') }}</p>
                    </div>
                    <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-3 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="play" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.url_import.button.start') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection
