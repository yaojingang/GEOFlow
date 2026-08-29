@extends('admin.layouts.app')

@php
    $oldTitlesText = old('titles_text', '');
    $titlesText = is_string($oldTitlesText) || is_int($oldTitlesText) || is_float($oldTitlesText)
        ? (string) $oldTitlesText
        : '';
    $importLimitCopy = __('admin.title_detail.import_limits', [
        'max_entries' => number_format((int) $importLimits['max_entries']),
        'title_max_characters' => (int) $importLimits['title_max_characters'],
        'keyword_max_characters' => (int) $importLimits['title_keyword_max_characters'],
        'max_text_size' => (string) $importLimits['max_text_size'],
    ]);
@endphp

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ __('admin.title_detail.modal_import') }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ $library->name }} · {{ __('admin.title_detail.import_format_dedupe') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ route('admin.title-libraries.import', ['libraryId' => (int) $library->id]) }}" class="overflow-hidden rounded-xl bg-white shadow" data-library-entry-form data-processing-label="{{ __('admin.message.processing') }}">
            @csrf

            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-8">
                <div class="min-w-0">
                    <label for="titles_text" class="block text-sm font-semibold text-gray-800">{{ __('admin.title_detail.field_titles') }}</label>
                    <textarea id="titles_text" name="titles_text" rows="14" required autofocus @error('titles_text') aria-invalid="true" aria-describedby="titles-text-help titles-text-error" @else aria-describedby="titles-text-help" @enderror class="mt-2 block min-h-80 w-full resize-y rounded-lg border-gray-300 px-3 py-3 font-mono text-sm leading-7 text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="{{ __('admin.title_detail.placeholder_titles') }}">{{ $titlesText }}</textarea>
                    <p id="titles-text-help" class="mt-2 max-w-3xl text-pretty text-xs leading-5 text-gray-500">
                        <span class="block">{{ __('admin.title_detail.import_format_line') }} · {{ __('admin.title_detail.import_format_pipe') }} · {{ __('admin.title_detail.import_format_dedupe') }}</span>
                        <span class="mt-1 block">{{ $importLimitCopy }}</span>
                    </p>
                    @error('titles_text')
                        <p id="titles-text-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-3 min-h-5 text-sm text-gray-600" role="status" aria-live="polite" aria-atomic="true" data-library-entry-status></p>
                </div>

                <aside class="h-fit min-w-0 rounded-xl bg-green-50 p-5 text-green-950 ring-1 ring-inset ring-green-200" aria-labelledby="title-import-format-title">
                    <h2 id="title-import-format-title" class="text-sm font-semibold">{{ __('admin.title_detail.import_format_title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm leading-6 text-green-900">
                        <li>{{ __('admin.title_detail.import_format_line') }}</li>
                        <li>{{ __('admin.title_detail.import_format_pipe') }}</li>
                        <li>{{ __('admin.title_detail.import_format_dedupe') }}</li>
                        <li>{{ $importLimitCopy }}</li>
                    </ul>
                    <div class="mt-5 rounded-lg bg-white p-3 font-mono text-xs leading-6 text-gray-700 shadow-sm">
                        <div>GEO content strategy</div>
                        <div>AI Search guide|AI Search</div>
                    </div>
                </aside>
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-65 disabled:active:scale-100" data-library-entry-submit>
                    <i data-lucide="upload" class="mr-2 h-4 w-4"></i>
                    <span data-library-entry-submit-label>{{ __('admin.title_detail.import_button') }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection
