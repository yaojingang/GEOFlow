@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.materials.heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.materials.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.authors.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                <i data-lucide="users" class="w-4 h-4 mr-2"></i>
                {{ __('admin.materials.author_manage') }}
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="key" class="h-6 w-6 text-blue-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.materials.keyword_libraries') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ __('admin.materials.library_count', ['count' => (int) $stats['keyword_libraries']]) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.materials.keyword_count', ['count' => (int) $stats['total_keywords']]) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="type" class="h-6 w-6 text-green-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.materials.title_libraries') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ __('admin.materials.library_count', ['count' => (int) $stats['title_libraries']]) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.materials.title_count', ['count' => (int) $stats['total_titles']]) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="image" class="h-6 w-6 text-purple-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.materials.image_libraries') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ __('admin.materials.library_count', ['count' => (int) $stats['image_libraries']]) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.materials.image_count', ['count' => (int) $stats['total_images']]) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="brain" class="h-6 w-6 text-orange-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.materials.knowledge_bases') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ __('admin.materials.library_count', ['count' => (int) $stats['knowledge_bases']]) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.materials.author_count', ['count' => (int) $stats['authors']]) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i data-lucide="key" class="w-5 h-5 text-blue-600 mr-2"></i>
                        {{ __('admin.materials.keyword_manage_title') }}
                    </h3>
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('admin.materials.view_all') }}</a>
                </div>
                <p class="mt-3 text-sm text-gray-600">{{ __('admin.materials.keywords_summary') }}</p>
                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.keyword_library_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_libraries', ['count' => (int) $stats['keyword_libraries']]) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.keyword_total_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_items', ['count' => (int) $stats['total_keywords']]) }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.manage_keyword_libraries') }}
                    </a>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i data-lucide="type" class="w-5 h-5 text-green-600 mr-2"></i>
                        {{ __('admin.materials.title_manage_title') }}
                    </h3>
                    <a href="{{ route('admin.title-libraries.index') }}" class="text-sm text-green-600 hover:text-green-800">{{ __('admin.materials.view_all') }}</a>
                </div>
                <p class="mt-3 text-sm text-gray-600">{{ __('admin.materials.titles_summary') }}</p>
                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.title_library_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_libraries', ['count' => (int) $stats['title_libraries']]) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.title_total_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_items', ['count' => (int) $stats['total_titles']]) }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="{{ route('admin.title-libraries.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                        <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.manage_title_libraries') }}
                    </a>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i data-lucide="image" class="w-5 h-5 text-purple-600 mr-2"></i>
                        {{ __('admin.materials.image_manage_title') }}
                    </h3>
                    <a href="{{ route('admin.image-libraries.index') }}" class="text-sm text-purple-600 hover:text-purple-800">{{ __('admin.materials.view_all') }}</a>
                </div>
                <p class="mt-3 text-sm text-gray-600">{{ __('admin.materials.images_summary') }}</p>
                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.image_library_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_libraries', ['count' => (int) $stats['image_libraries']]) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.image_total_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_images', ['count' => (int) $stats['total_images']]) }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="{{ route('admin.image-libraries.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                        <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.manage_image_libraries') }}
                    </a>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i data-lucide="brain" class="w-5 h-5 text-orange-600 mr-2"></i>
                        {{ __('admin.materials.knowledge_manage_title') }}
                    </h3>
                    <a href="{{ route('admin.knowledge-bases.index') }}" class="text-sm text-orange-600 hover:text-orange-800">{{ __('admin.materials.view_all') }}</a>
                </div>
                <p class="mt-3 text-sm text-gray-600">{{ __('admin.materials.knowledge_summary') }}</p>
                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.knowledge_base_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.unit_libraries', ['count' => (int) $stats['knowledge_bases']]) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ __('admin.materials.author_total_count') }}</span>
                        <span class="font-medium text-gray-900">{{ __('admin.materials.author_count', ['count' => (int) $stats['authors']]) }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                        <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.manage_knowledge_bases') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-2xl mb-8 overflow-hidden">
            <div class="p-6 lg:p-8">
                <div class="max-w-5xl">
                    <span class="inline-flex items-center rounded-full bg-cyan-50 px-3 py-1 text-sm font-medium text-cyan-700">
                        <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.url_import') }}
                    </span>
                    <h2 class="mt-5 text-3xl font-bold tracking-tight text-gray-900">{{ __('admin.materials.url_import_title') }}</h2>
                    <p class="mt-3 text-base leading-7 text-gray-600">{{ __('admin.materials.url_import_description') }}</p>
                </div>

                <form method="POST" action="{{ route('admin.url-import.store') }}" class="mt-7">
                    @csrf
                    <label for="quick_url_import_url" class="block text-sm font-semibold text-gray-800">{{ __('admin.materials.url_import_target_label') }}</label>
                    <div class="mt-3 flex flex-col gap-3 lg:flex-row">
                        <input
                            id="quick_url_import_url"
                            name="url"
                            type="text"
                            required
                            value="{{ old('url') }}"
                            placeholder="{{ __('admin.materials.url_import_placeholder') }}"
                            class="block min-h-14 w-full rounded-xl border-gray-300 px-5 text-base shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                        @foreach (['knowledge', 'keywords', 'titles'] as $output)
                            <input type="hidden" name="outputs[]" value="{{ $output }}">
                        @endforeach
                        <button type="submit" class="inline-flex min-h-14 shrink-0 items-center justify-center rounded-xl border border-transparent bg-blue-600 px-7 text-base font-semibold text-white shadow-sm hover:bg-blue-700">
                            <i data-lucide="globe" class="w-5 h-5 mr-2"></i>
                            {{ __('admin.materials.url_import_start') }}
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">{{ __('admin.url_import.help.url_optional_scheme') }}</p>
                    @error('url')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </form>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <a href="{{ route('admin.url-import') }}" class="inline-flex items-center text-sm font-medium text-blue-600 hover:text-blue-800">
                        <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.url_import.section.new_job') }}
                    </a>
                    <a href="{{ route('admin.url-import.history') }}" class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-800">
                        <i data-lucide="history" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.materials.url_import_history') }}
                    </a>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-lg border border-gray-200 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.url_import_flow_label') }}</div>
                        <div class="mt-2 text-base font-semibold text-gray-900">{{ __('admin.materials.url_import_flow_title') }}</div>
                        <p class="mt-2 text-sm text-gray-600">{{ __('admin.materials.url_import_flow_desc') }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.url_import_assets_label') }}</div>
                        <div class="mt-2 text-base font-semibold text-gray-900">{{ __('admin.materials.url_import_assets_title') }}</div>
                        <p class="mt-2 text-sm text-gray-600">{{ __('admin.materials.url_import_assets_desc') }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <div class="text-sm font-medium text-gray-500">{{ __('admin.materials.url_import_stage_label') }}</div>
                        <div class="mt-2 text-base font-semibold text-gray-900">{{ __('admin.materials.url_import_stage_title') }}</div>
                        <p class="mt-2 text-sm text-gray-600">{{ __('admin.materials.url_import_stage_desc') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.materials.quick_actions') }}</h3>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="key" class="w-8 h-8 text-blue-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900">{{ __('admin.materials.keyword_libraries') }}</h4>
                            <p class="text-sm text-gray-500">{{ __('admin.materials.manage_keywords_short') }}</p>
                        </div>
                    </a>
                    <a href="{{ route('admin.title-libraries.index') }}" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="type" class="w-8 h-8 text-green-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900">{{ __('admin.materials.title_libraries') }}</h4>
                            <p class="text-sm text-gray-500">{{ __('admin.materials.manage_titles_short') }}</p>
                        </div>
                    </a>
                    <a href="{{ route('admin.image-libraries.index') }}" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="image" class="w-8 h-8 text-purple-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900">{{ __('admin.materials.image_libraries') }}</h4>
                            <p class="text-sm text-gray-500">{{ __('admin.materials.manage_images_short') }}</p>
                        </div>
                    </a>
                    <a href="{{ route('admin.knowledge-bases.index') }}" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="brain" class="w-8 h-8 text-orange-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900">{{ __('admin.materials.knowledge_bases') }}</h4>
                            <p class="text-sm text-gray-500">{{ __('admin.materials.manage_knowledge_short') }}</p>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
