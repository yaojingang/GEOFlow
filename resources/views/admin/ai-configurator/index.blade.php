@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_configurator.heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_configurator.subtitle') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
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
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_configurator.overview') }}</h3>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
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
                </div>
            </div>
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
