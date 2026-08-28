@extends('admin.layouts.app')

@php
    $normalizeScalar = static fn (mixed $value): string => is_string($value) || is_int($value) || is_float($value)
        ? (string) $value
        : '';
    $titleValue = $normalizeScalar(old('title', ''));
    $keywordValue = $normalizeScalar(old('keyword', ''));
@endphp

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ __('admin.title_detail.modal_add') }}</h1>
                <p class="mt-1 text-pretty text-sm leading-6 text-gray-600">{{ $library->name }}</p>
            </div>
        </header>

        <form method="POST" action="{{ route('admin.title-libraries.titles.store', ['libraryId' => (int) $library->id]) }}" class="overflow-hidden rounded-xl bg-white shadow" data-library-entry-form data-processing-label="{{ __('admin.message.processing') }}">
            @csrf

            <div class="space-y-5 px-5 py-6 sm:px-6">
                <div>
                    <label for="title" class="block text-sm font-semibold text-gray-800">{{ __('admin.title_detail.field_title') }}</label>
                    <input id="title" name="title" type="text" required autofocus maxlength="500" autocomplete="off" value="{{ $titleValue }}" @error('title') aria-invalid="true" aria-describedby="title-error" @enderror class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="{{ __('admin.title_detail.placeholder_title') }}">
                    @error('title')
                        <p id="title-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="keyword" class="block text-sm font-semibold text-gray-800">{{ __('admin.title_detail.field_keyword') }}</label>
                    <input id="keyword" name="keyword" type="text" maxlength="200" autocomplete="off" value="{{ $keywordValue }}" @error('keyword') aria-invalid="true" aria-describedby="keyword-error" @enderror class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="{{ __('admin.title_detail.placeholder_keyword') }}">
                    @error('keyword')
                        <p id="keyword-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <p class="min-h-5 text-sm text-gray-600" role="status" aria-live="polite" aria-atomic="true" data-library-entry-status></p>
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-green-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-65 disabled:active:scale-100" data-library-entry-submit>
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    <span data-library-entry-submit-label>{{ __('admin.button.add') }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection
