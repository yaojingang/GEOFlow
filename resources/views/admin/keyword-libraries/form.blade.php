@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.keyword-libraries.update', ['libraryId' => (int) $libraryId])
        : route('admin.keyword-libraries.store');
    $contextValue = in_array($context ?? 'index', ['index', 'detail'], true) ? ($context ?? 'index') : 'index';
    $returnUrl = $isEdit && $contextValue === 'detail'
        ? route('admin.keyword-libraries.detail', ['libraryId' => (int) $libraryId])
        : route('admin.keyword-libraries.index');
    $normalizeScalar = static function (mixed $value, string $fallback = ''): string {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : $fallback;
    };
    $nameFallback = $normalizeScalar($libraryForm['name'] ?? '');
    $descriptionFallback = $normalizeScalar($libraryForm['description'] ?? '');
    $nameValue = $normalizeScalar(old('name', $nameFallback), $nameFallback);
    $descriptionValue = $normalizeScalar(old('description', $descriptionFallback), $descriptionFallback);
@endphp

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ $returnUrl }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.button.edit').' · '.$nameFallback : __('admin.keyword_libraries.modal_create') }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.keyword_libraries.subtitle') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ $formAction }}" class="overflow-hidden rounded-xl bg-white shadow" data-library-entry-form data-processing-label="{{ __('admin.message.processing') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
                <input type="hidden" name="context" value="{{ $contextValue }}">
            @endif

            <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.common.basic_info') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.keyword_libraries.subtitle') }}</p>
            </div>

            <div class="space-y-6 px-5 py-6 sm:px-6">
                <div>
                    <label for="name" class="block text-sm font-semibold text-gray-800">{{ __('admin.keyword_libraries.field_name') }}</label>
                    <input id="name" type="text" name="name" required autofocus maxlength="100" autocomplete="off" value="{{ $nameValue }}" @error('name') aria-invalid="true" aria-describedby="keyword-library-name-error" @enderror class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_name') }}">
                    @error('name')
                        <p id="keyword-library-name-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="description" class="block text-sm font-semibold text-gray-800">{{ __('admin.keyword_libraries.field_description') }}</label>
                    <textarea id="description" name="description" rows="5" @error('description') aria-invalid="true" aria-describedby="keyword-library-description-error" @enderror class="mt-2 block w-full resize-y rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_description') }}">{{ $descriptionValue }}</textarea>
                    @error('description')
                        <p id="keyword-library-description-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                @error('context')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <p class="min-h-5 basis-full text-sm text-gray-600 sm:mr-auto sm:basis-auto" role="status" aria-live="polite" aria-atomic="true" data-library-entry-status></p>
                <a href="{{ $returnUrl }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-65 disabled:active:scale-100" data-library-entry-submit>
                    <span data-library-entry-submit-label>{{ __('admin.button.save') }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection
