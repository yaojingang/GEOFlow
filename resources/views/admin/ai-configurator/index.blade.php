@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="sr-only">
            <h1>{{ __('admin.ai_configurator.heading') }}</h1>
            <p>{{ __('admin.ai_configurator.subtitle') }}</p>
        </div>

        <section class="overflow-hidden rounded-lg bg-white shadow" data-ai-configurator-overview aria-labelledby="ai-configurator-overview-heading">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 id="ai-configurator-overview-heading" class="text-lg font-medium text-gray-900">{{ __('admin.ai_configurator.overview') }}</h2>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600">{{ (int) ($stats['model_count'] ?? 0) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.ai_configurator.active_models') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-green-600">{{ (int) ($stats['prompt_count'] ?? 0) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.ai_configurator.prompt_templates') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600">{{ number_format((int) ($stats['total_usage'] ?? 0)) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.ai_configurator.total_calls') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-orange-600">{{ number_format((int) ($stats['today_usage'] ?? 0)) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.ai_configurator.today_calls') }}</div>
                    </div>
                    @if ($showSystemCollectionConfiguration ?? false)
                    <div class="text-center">
                        <div class="text-2xl font-bold text-teal-600">{{ (int) ($stats['search_provider_count'] ?? 0) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.ai_configurator.active_search_providers') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-rose-600">{{ number_format((int) ($stats['visibility_failed_runs'] ?? 0)) }}</div>
                        <div class="text-sm text-gray-500">{{ __('admin.ai_configurator.visibility_failed_runs') }}</div>
                    </div>
                    @endif
                </div>
            </div>
        </section>

        <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4" data-ai-configurator-modules>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                                <i data-lucide="cpu" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.ai_configurator.models_title') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ __('admin.ai_configurator.models_desc') }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.ai-models.index') }}" class="font-medium text-blue-600 hover:text-blue-500">
                            {{ __('admin.ai_configurator.models_action') }} <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                                <i data-lucide="message-square" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.ai_configurator.prompts_title') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ __('admin.ai_configurator.prompts_desc') }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.ai-prompts') }}" class="font-medium text-green-600 hover:text-green-500">
                            {{ __('admin.ai_configurator.prompts_action') }} <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                                <i data-lucide="settings" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.ai_configurator.special_title') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ __('admin.ai_configurator.special_desc') }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.ai-special-prompts') }}" class="font-medium text-purple-600 hover:text-purple-500">
                            {{ __('admin.ai_configurator.special_action') }} <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            @if ($showSystemCollectionConfiguration ?? false)
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-teal-500 rounded-md flex items-center justify-center">
                                <i data-lucide="search-check" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.ai_configurator.search_title') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ __('admin.ai_configurator.search_desc') }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.ai-source-providers.index') }}" class="font-medium text-teal-600 hover:text-teal-500">
                            {{ __('admin.ai_configurator.search_action') }} <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>
            @endif
        </div>

        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i data-lucide="info" class="h-5 w-5 text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">{{ __('admin.ai_configurator.help_title') }}</h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>{{ __('admin.ai_configurator.help_models') }}</li>
                            @if ($showSystemCollectionConfiguration ?? false)
                                <li>{{ __('admin.ai_configurator.help_search_providers') }}</li>
                            @endif
                            <li>{{ __('admin.ai_configurator.help_content_prompts') }}</li>
                            <li>{{ __('admin.ai_configurator.help_special_prompts') }}</li>
                            <li>{{ __('admin.ai_configurator.help_pipeline') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
