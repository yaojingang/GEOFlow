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

        <div class="grid grid-cols-1 gap-8 {{ $isSuperAdmin ? 'lg:grid-cols-2' : '' }}">
            @if($isSuperAdmin)
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
                                placeholder="{{ html_entity_decode(__('admin.security.words_placeholder'), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}"
                            >{{ old('words') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.security.words_help') }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="severity" class="block text-sm font-medium text-gray-700">{{ __('admin.security.severity') }}</label>
                                <select id="severity" name="severity" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                    <option value="warning" @selected(old('severity', 'warning') === 'warning')>{{ __('admin.security.severity_warning') }}</option>
                                    <option value="blocked" @selected(old('severity') === 'blocked')>{{ __('admin.security.severity_blocked') }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="category" class="block text-sm font-medium text-gray-700">{{ __('admin.security.category') }}</label>
                                <input id="category" name="category" type="text" value="{{ old('category', 'sensitive') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            </div>
                        </div>

                        <div>
                            <span class="block text-sm font-medium text-gray-700">{{ __('admin.security.applies_to') }}</span>
                            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach (['title', 'excerpt', 'content', 'keywords', 'meta_description'] as $field)
                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                        <input type="checkbox" name="applies_to[]" value="{{ $field }}" @checked(in_array($field, old('applies_to', []), true)) class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        {{ __('admin.security.field_'.$field) }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.security.applies_to_help') }}</p>
                        </div>

                        <div>
                            <label for="suggestion" class="block text-sm font-medium text-gray-700">{{ __('admin.security.suggestion') }}</label>
                            <textarea id="suggestion" name="suggestion" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.security.suggestion_placeholder') }}">{{ old('suggestion') }}</textarea>
                        </div>

                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox" name="is_enabled" value="1" @checked((string) old('is_enabled', '1') === '1') class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">{{ __('admin.security.enabled') }}</span>
                                <span class="block text-xs text-gray-500">{{ __('admin.security.enabled_help') }}</span>
                            </span>
                        </label>

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="shield-plus" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.security.add_sensitive_words') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            @endif

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.security.words_list') }}</h3>
                </div>
                <div class="px-6 py-6">
                    @if (! empty($sensitiveWords))
                        <div class="max-h-[34rem] overflow-y-auto">
                            <div class="space-y-3">
                                @foreach ($sensitiveWords as $word)
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-semibold text-gray-900">{{ $word['word'] }}</span>
                                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $word['severity'] === 'blocked' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">
                                                        {{ $word['severity'] === 'blocked' ? __('admin.security.severity_blocked') : __('admin.security.severity_warning') }}
                                                    </span>
                                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $word['is_enabled'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600' }}">
                                                        {{ $word['is_enabled'] ? __('admin.security.status_enabled') : __('admin.security.status_disabled') }}
                                                    </span>
                                                </div>
                                                <p class="mt-1 text-xs text-gray-500">{{ $word['category'] }} · {{ __('admin.security.word_added_at', ['value' => $word['created_at']]) }}</p>
                                                @if($word['suggestion'] !== '')
                                                    <p class="mt-2 text-xs leading-5 text-gray-600">{{ $word['suggestion'] }}</p>
                                                @endif
                                            </div>
                                            @if($isSuperAdmin)
                                                <form method="POST" action="{{ route('admin.site-settings.sensitive-words.delete', ['wordId' => $word['id']]) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" aria-label="{{ __('admin.security.confirm_delete_word') }}" onclick="return confirm(@js(__('admin.security.confirm_delete_word')))" class="rounded-md p-1.5 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800">
                                                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>

                                        @if($isSuperAdmin)
                                        <details class="mt-3 border-t border-gray-200 pt-3">
                                            <summary class="cursor-pointer text-xs font-semibold text-blue-700">{{ __('admin.security.edit_rule') }}</summary>
                                            <form method="POST" action="{{ route('admin.site-settings.sensitive-words.update', ['wordId' => $word['id']]) }}" class="mt-4 space-y-3">
                                                @csrf
                                                @method('PUT')
                                                <input name="word" type="text" required value="{{ $word['word'] }}" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                    <select name="severity" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                                        <option value="warning" @selected($word['severity'] === 'warning')>{{ __('admin.security.severity_warning') }}</option>
                                                        <option value="blocked" @selected($word['severity'] === 'blocked')>{{ __('admin.security.severity_blocked') }}</option>
                                                    </select>
                                                    <input name="category" type="text" required value="{{ $word['category'] }}" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                                </div>
                                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                                    @foreach (['title', 'excerpt', 'content', 'keywords', 'meta_description'] as $field)
                                                        <label class="flex items-center gap-2 text-xs text-gray-600">
                                                            <input type="checkbox" name="applies_to[]" value="{{ $field }}" @checked(in_array($field, $word['applies_to'], true)) class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                            {{ __('admin.security.field_'.$field) }}
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <textarea name="suggestion" rows="2" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.security.suggestion_placeholder') }}">{{ $word['suggestion'] }}</textarea>
                                                <div class="flex items-center justify-between gap-3">
                                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                                        <input type="hidden" name="is_enabled" value="0">
                                                        <input type="checkbox" name="is_enabled" value="1" @checked($word['is_enabled']) class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                        {{ __('admin.security.enabled') }}
                                                    </label>
                                                    <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">{{ __('admin.security.save_rule') }}</button>
                                                </div>
                                            </form>
                                        </details>
                                        @endif
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
