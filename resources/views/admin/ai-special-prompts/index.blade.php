@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.ai.configurator') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_special.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_special.subtitle') }}</p>
                </div>
            </div>
        </div>

        <div class="space-y-8">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                                <i data-lucide="key" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_special.keyword_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_special.keyword_subtitle') }}</p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-6">
                    <form method="POST" action="{{ route('admin.ai-special-prompts.keyword') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="keyword_content" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_special.keyword_field') }}</label>
                            <textarea name="keyword_content" id="keyword_content" rows="8" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm"
                                      placeholder="{{ __('admin.ai_special.keyword_placeholder') }}">{{ $keywordPromptContent }}</textarea>
                            <p class="mt-2 text-sm text-gray-500">{{ __('admin.ai_special.keyword_help') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{!! __('admin.ai_special.variable_help') !!}</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.ai_special.keyword_save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-orange-500 rounded-md flex items-center justify-center">
                                <i data-lucide="file-text" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-gray-900">{{ __('admin.ai_special.description_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_special.description_subtitle') }}</p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-6">
                    <form method="POST" action="{{ route('admin.ai-special-prompts.description') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="description_content" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_special.description_field') }}</label>
                            <textarea name="description_content" id="description_content" rows="8" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                      placeholder="{{ __('admin.ai_special.description_placeholder') }}">{{ $descriptionPromptContent }}</textarea>
                            <p class="mt-2 text-sm text-gray-500">{{ __('admin.ai_special.description_help') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{!! __('admin.ai_special.variable_help') !!}</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                                <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.ai_special.description_save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i data-lucide="info" class="h-5 w-5 text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">{{ __('admin.ai_special.help_title') }}</h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>{{ __('admin.ai_special.help_keyword') }}</li>
                            <li>{{ __('admin.ai_special.help_description') }}</li>
                            <li>{{ __('admin.ai_special.help_variables') }}</li>
                            <li>{{ __('admin.ai_special.help_auto_apply') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
@endpush

