@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.security.page_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.security.page_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.site-settings.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                {{ __('admin.security.back_to_site_settings') }}
            </a>
        </div>

        <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-3">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-alert" class="h-8 w-8 text-red-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.security.total_sensitive_words') }}</dt>
                                <dd class="text-2xl font-bold text-gray-900">{{ count($sensitiveWords) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="md:col-span-2 bg-blue-50 border border-blue-100 rounded-lg p-5">
                <div class="flex gap-3">
                    <i data-lucide="info" class="h-5 w-5 text-blue-600 mt-0.5"></i>
                    <div>
                        <h2 class="text-sm font-semibold text-blue-900">{{ __('admin.security.tips_title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('admin.security.sensitive_words_desc') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.add_sensitive_words') }}</h3>
                </div>
                <div class="px-6 py-6">
                    <form method="POST" action="{{ route('admin.site-settings.sensitive-words.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="words" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.security.words_label') }}</label>
                            <textarea
                                name="words"
                                id="words"
                                rows="10"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                placeholder="{{ __('admin.security.words_placeholder') }}"
                            >{{ old('words') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.security.words_help') }}</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="shield-plus" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.security.add_sensitive_words') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.words_list') }}</h3>
                </div>
                <div class="px-6 py-6">
                    @if (! empty($sensitiveWords))
                        <div class="max-h-[34rem] overflow-y-auto">
                            <div class="space-y-2">
                                @foreach ($sensitiveWords as $word)
                                    <div class="flex items-center justify-between gap-4 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                        <div class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-gray-900">{{ $word['word'] }}</span>
                                            <span class="mt-1 block text-xs text-gray-500">
                                                {{ __('admin.security.word_added_at', ['value' => $word['created_at']]) }}
                                            </span>
                                        </div>
                                        <form method="POST" action="{{ route('admin.site-settings.sensitive-words.delete', ['wordId' => $word['id']]) }}" class="inline">
                                            @csrf
                                            <button type="submit" onclick="return confirm(@js(__('admin.security.confirm_delete_word')))" class="text-red-600 hover:text-red-800 transition-colors">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="rounded-lg border border-dashed border-gray-300 px-6 py-10 text-center">
                            <i data-lucide="shield-alert" class="mx-auto h-8 w-8 text-gray-300"></i>
                            <p class="mt-3 text-sm text-gray-500">{{ __('admin.security.empty_words') }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
