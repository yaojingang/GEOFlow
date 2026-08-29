@extends('admin.layouts.app')

@php
    $oldKeywordsText = old('keywords_text', '');
    $keywordsText = is_string($oldKeywordsText) || is_int($oldKeywordsText) || is_float($oldKeywordsText)
        ? (string) $oldKeywordsText
        : '';
    $importLimitCopy = __('admin.keyword_libraries.import_limits', [
        'max_entries' => number_format((int) $importLimits['max_entries']),
        'keyword_max_characters' => (int) $importLimits['keyword_max_characters'],
        'max_text_size' => (string) $importLimits['max_text_size'],
    ]);
@endphp

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]) }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ __('admin.keyword_libraries.modal_import') }} {{ $library->name }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.keyword_libraries.format_line') }}，{{ __('admin.keyword_libraries.format_dedupe') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ route('admin.keyword-libraries.import', ['libraryId' => (int) $library->id]) }}" class="overflow-hidden rounded-xl bg-white shadow" data-library-entry-form data-processing-label="{{ __('admin.message.processing') }}">
            @csrf

            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-8">
                <div class="min-w-0">
                    <label for="keywords_text" class="block text-sm font-semibold text-gray-800">{{ __('admin.keyword_libraries.field_keywords') }}</label>
                    <textarea id="keywords_text" name="keywords_text" rows="14" required autofocus @error('keywords_text') aria-invalid="true" aria-describedby="keywords-text-help keywords-text-error" @else aria-describedby="keywords-text-help" @enderror class="mt-2 block min-h-80 w-full resize-y rounded-lg border-gray-300 px-3 py-3 font-mono text-sm leading-7 text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_keywords') }}">{{ $keywordsText }}</textarea>
                    <p id="keywords-text-help" class="mt-2 max-w-3xl text-pretty text-xs leading-5 text-gray-500">
                        <span class="block">{{ __('admin.keyword_libraries.format_line') }} · {{ __('admin.keyword_libraries.format_comma') }} · {{ __('admin.keyword_libraries.format_dedupe') }}</span>
                        <span class="mt-1 block">{{ $importLimitCopy }}</span>
                    </p>
                    @error('keywords_text')
                        <p id="keywords-text-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-3 min-h-5 text-sm text-gray-600" role="status" aria-live="polite" aria-atomic="true" data-library-entry-status></p>
                </div>

                <aside class="h-fit min-w-0 rounded-xl bg-blue-50 p-5 text-blue-950 ring-1 ring-inset ring-blue-200" aria-labelledby="keyword-import-format-title">
                    <h2 id="keyword-import-format-title" class="text-sm font-semibold">{{ __('admin.keyword_libraries.format_title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm leading-6 text-blue-900">
                        <li>{{ __('admin.keyword_libraries.format_line') }}</li>
                        <li>{{ __('admin.keyword_libraries.format_comma') }}</li>
                        <li>{{ __('admin.keyword_libraries.format_dedupe') }}</li>
                        <li>{{ $importLimitCopy }}</li>
                    </ul>
                    <div class="mt-5 rounded-lg bg-white p-3 font-mono text-xs leading-6 text-gray-700 shadow-sm">
                        <div>GEO</div>
                        <div>AI Search, SEO</div>
                    </div>
                </aside>
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-65 disabled:active:scale-100" data-library-entry-submit>
                    <i data-lucide="upload" class="mr-2 h-4 w-4"></i>
                    <span data-library-entry-submit-label>{{ __('admin.keyword_libraries.import_button') }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection
